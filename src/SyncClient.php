<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel;

use Cbox\Sync\Client\Laravel\Contracts\RebasePolicy;
use Cbox\Sync\Client\Laravel\Contracts\SyncTransport;
use Cbox\Sync\Client\Laravel\Support\Wire;
use Cbox\Sync\Client\Laravel\ValueObjects\PushOutcome;
use Cbox\Sync\Client\Laravel\ValueObjects\StaleField;
use Cbox\Sync\Client\Laravel\ValueObjects\SyncResponse;
use Cbox\Sync\Client\Outbox;
use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldOperation;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\Views\BootstrapToken;
use Cbox\Sync\Views\MultiViewClient;
use Cbox\Sync\Views\ViewCursor;

/**
 * One device's side of the protocol: drain the queue, then catch up.
 *
 * The four push outcomes are the whole point of this class. Every application
 * that talks to the protocol has to get them right, and getting one wrong is
 * either silent data loss or a queue that never moves again.
 */
class SyncClient
{
    /**
     * How many times one write is rethought before the server is left to
     * decide. Each refusal means someone else wrote the same field in the
     * meantime; past this, retrying is racing a busy record, and preserving
     * both values loses nothing.
     */
    public const REBASE_ATTEMPTS = 3;

    /**
     * The space to queue under for a type that has no scope.
     *
     * A queued write is keyed by the scope it will be pushed under, so that a
     * push for one tenant never sends another tenant's work. A type with no
     * scope still needs a key; this is it, and it is never sent to the server.
     */
    public const UNSCOPED = '~';

    /**
     * The server's answers that refuse a write for good. Everything else is
     * either a success, a pause, or not the server speaking at all.
     */
    /** Processed answers that mean the write will never be applied. */
    private const REFUSED = ['rejected', 'validation_failed', 'precondition_failed'];

    private const TERMINAL = [
        'invalid_request',
        'invalid_field_value',
        'too_many_operations',
        'field_not_writable',
        'body_too_large',
        'unsupported_media_type',
        'forbidden',
        'unknown_type',
        'protocol_violation',
    ];

    public function __construct(
        private readonly SyncTransport $transport,
        private readonly Outbox $outbox,
        private readonly MultiViewClient $replica,
        private readonly ViewIndex $views,
        private ?RebasePolicy $rebase = null,
        private readonly Contracts\PushLock $lock = new Support\NoLock,
        /** @var array<string, array<string, string>> entity type => [field => the type it points at] */
        private readonly array $references = [],
        /** @var array<string, string> entity type => the type whose id is its scope */
        private readonly array $scopedBy = [],
        /** Records per bootstrap page when a call does not say. */
        private readonly int $pageSize = 100,
    ) {}

    /**
     * Decide conflicts on this device instead of on the server.
     *
     * With a policy, a write that meets a newer edit of the same field is
     * refused rather than preserved; the policy is asked what the edit should
     * now be, and it is sent again, knowing what it replaces. Null goes back
     * to letting the server's resolver decide.
     */
    public function rebaseWith(?RebasePolicy $policy): static
    {
        $this->rebase = $policy;

        return $this;
    }

    public function replica(): MultiViewClient
    {
        return $this->replica;
    }

    /** Where an application queues its writes. */
    public function outbox(): Outbox
    {
        return $this->outbox;
    }

    /**
     * The key to queue a write under: this type, in this scope.
     *
     * The id is the record's name once the server has given it one, or a
     * handle you made up for a record you are creating.
     */
    public function key(string $type, ?string $scope, string $id): EntityKey
    {
        return new EntityKey($scope ?? self::UNSCOPED, $type, $id);
    }

    /**
     * A record as this device last saw it, by the names the application uses.
     *
     * The replica keys records by the server's space, which is the server's own
     * mapping of type and scope - a Laravel model scoped by team_id lives in
     * "tasks:team-1", not "team-1". This finds it without the application
     * having to know that mapping. Null before the view's first sync.
     */
    public function record(string $type, ?string $scope, string $id): ?EntityRecord
    {
        $space = $this->space($type, $scope);
        $record = $space === null ? null : $this->replica->record(new EntityKey($space, $type, $id));
        if ($record === null) {
            return null;
        }

        // Keyed the way the application queues: queueing an edit on
        // $record->entity is the natural next step, and under the server's
        // space it would sit where no push for this scope ever looks.
        return new EntityRecord($this->key($type, $scope, $id), $record->version, $record->fields, $record->deleted, $record->deletion);
    }

    /**
     * Send what this device owes, then take what it is owed.
     *
     * The one call a notification handler makes. Push first: a device that
     * pulls before pushing reads a server that has not seen its own writes
     * yet, so its edits come back a round trip later and anything it shows in
     * the meantime is behind its own user.
     *
     * Nothing here depends on having been notified. Calling it on a timer is
     * the same operation, which is what lets a missed signal cost promptness
     * rather than correctness.
     */
    public function sync(string $type, ?string $scope = null, ?int $pageSize = null): PushOutcome
    {
        $outcome = $this->push($type, $scope);
        try {
            $caughtUp = $this->pull($type, $scope, $pageSize);
        } catch (Exceptions\SyncRequestFailed $failed) {
            // The push already happened, and what it reports - writes the
            // server refused - is the application's to tell the user. Thrown
            // away with a failed pull, that report was gone for good.
            return $outcome->withPull($failed, false);
        }

        return $outcome->withPull(null, $caughtUp);
    }

    /**
     * The space this view lives in, once this device has seen it.
     *
     * A change notification names a space, because that is the boundary the log
     * is kept in - but a client works in types and scopes and cannot map one to
     * the other: the mapping is the server's own authorization policy. The
     * context saved during bootstrap carries it, so after the first sync a
     * device knows which channel to listen on.
     *
     * Null before that first sync. Subscribe after it, or subscribe to the
     * scopes your application already knows and let this confirm them.
     */
    public function space(string $type, ?string $scope = null): ?string
    {
        $fingerprint = $this->views->fingerprint($type, $scope);
        if ($fingerprint === null) {
            return null;
        }

        return $this->replica->contextFor($fingerprint)?->space;
    }

    /**
     * Send queued mutations, oldest first, until the queue empties or the
     * server tells us to stop.
     *
     * Only one push at a time per device.
     *
     * A queue worker and a scheduler draining together would send the same
     * head under the same number, and a late answer to one could be read as
     * the other's. When another push holds the lock this returns at once with
     * retryLater: the one already running is doing the work.
     */
    public function push(string $type, ?string $scope = null): PushOutcome
    {
        if (! $this->lock->acquire()) {
            return new PushOutcome(0, 0, retryLater: true);
        }

        try {
            return $this->drain($type, $scope);
        } finally {
            $this->lock->release();
        }
    }

    private function drain(string $type, ?string $scope): PushOutcome
    {
        $sent = 0;
        $abandoned = 0;
        /** @var array<string, int> $resumed the last gap answer per stream */
        $resumed = [];
        $outcomes = [];
        $named = [];
        $rebased = 0;
        /** @var array<string, int> $refusals */
        $refusals = [];
        $label = $scope ?? self::UNSCOPED;
        // By reference: the counts at the moment the queue stops, not at the start.
        $wait = function (Mutation $blocked, SyncResponse $response, bool $unauthenticated = false) use (&$sent, &$abandoned, &$outcomes, &$named, &$rebased): PushOutcome {
            return new PushOutcome(
                $sent, $abandoned, retryLater: true, outcomes: $outcomes, named: $named, rebased: $rebased, unauthenticated: $unauthenticated,
                blockedBy: $blocked->id, httpStatus: $response->status, error: self::redirected($response) ? 'redirected' : $response->error(), retryAfter: $response->retryAfter,
            );
        };

        // Only this type's queue, in this scope - the wire carries the type and
        // scope the mutation was queued under, and draining anything wider
        // would submit another type's or another tenant's writes.
        while (($next = $this->outbox->peek($type, $label)) !== null) {
            // Queued before its parent was named - by another process, say -
            // it still carries the handle. Mapped first, then looked at again.
            if ($this->outbox->mapNames($next, $this->references, $this->scopedBy)) {
                continue;
            }
            // A parent created offline goes first - exactly its create, from
            // whatever type or scope it was queued under - so the child's
            // reference (or its scope) is rewritten to the parent's real id
            // before the child is sent. A parent that was abandoned will not
            // exist, and a child pointing at it is abandoned with it rather than
            // sent holding a handle the server never heard of.
            [$choice, $orphaned] = $this->nextToSend($next, []);
            if ($orphaned !== null) {
                // parent_unknown when the parent may exist after all - its
                // name has to be found before this can go - and never counted
                // as an answer: this write was not sent.
                $this->outbox->abandon($choice, $orphaned, answered: false);
                $abandoned++;

                continue;
            }
            $mutation = $choice->id === $next->id ? $this->outbox->head($type, $label) : $this->outbox->take($choice->id);
            if ($mutation === null) {
                continue;
            }
            $sendScope = $mutation->entity->space === self::UNSCOPED ? null : $mutation->entity->space;

            $pull = $this->rebase !== null && ($refusals[$mutation->id] ?? 0) < self::REBASE_ATTEMPTS;
            $response = $this->transport->post('push', ['type' => $mutation->entity->type, 'scope' => $sendScope]
                + Wire::mutationToWire($mutation)
                + ($pull ? ['on_conflict' => 'pull'] : []));

            $status = $this->protocolStatus($response);
            if ($status === null) {
                // Not a protocol answer at all - an HTML error page, a proxy
                // timeout, an empty body. The queue is left exactly as it is.
                // Acknowledging would lose the write; abandoning would lose it
                // permanently, and a transient outage would drain everything.
                return $wait($mutation, $response);
            }

            if ($status === 'pull_required' && $this->rebase !== null) {
                // Nothing was stored, so the same mutation goes again - rethought
                // against what the refusal reported. Pulling first is not needed
                // for that: the refusal carries exactly the fields in question,
                // and sync() pulls the rest straight after.
                if (! $this->rebaseOnto($mutation, $response, $this->rebase)) {
                    return $wait($mutation, $response);
                }
                $refusals[$mutation->id] = ($refusals[$mutation->id] ?? 0) + 1;
                $rebased++;

                continue;
            }
            if ($status === 'pull_required') {
                // Only ever sent on_conflict=pull with a policy in hand, so this
                // is a server answering a question it was not asked. Leaving the
                // queue alone is the one response that cannot lose the write.
                return $wait($mutation, $response);
            }

            if ($status === 'receipt_pruned') {
                // This write may already have been applied, and its answer is
                // gone: final, never resent - but the stream goes on from where
                // the server says it is.
                // Its own position counts as acknowledged: jumping to the
                // server's would renumber older replays behind it past the
                // pruned range, and they would be applied a second time.
                $known = $response->body->acknowledged_sequence ?? null;
                $abandoned += $this->outbox->settledUnknown($mutation, is_int($known) ? $known : null);

                continue;
            }

            if ($status === 'mutation_gap') {
                $acknowledged = $response->body->acknowledged_sequence ?? 0;
                $acknowledged = is_int($acknowledged) ? $acknowledged : 0;
                // Renumber from where the server actually is and try again.
                // Twice in a row with the same answer means resending will
                // never help, and looping against a live server is far worse
                // than stopping and letting the caller see it.
                if (($resumed[$mutation->replica->id] ?? null) === $acknowledged) {
                    return $wait($mutation, $response);
                }
                $resumed[$mutation->replica->id] = $acknowledged;
                $this->outbox->resumeAfter($mutation, $acknowledged);

                continue;
            }
            unset($resumed[$mutation->replica->id]);

            if ($status === 'processed') {
                $outcomes[] = $this->outcome($mutation, $response);
                $answer = $response->body->status ?? null;
                if (in_array($answer, self::REFUSED, true)) {
                    // Processed and refused: kept, under the server's word
                    // for why, until the application dismisses it - and a
                    // refused create goes on holding back what depends on it.
                    $this->outbox->refused($mutation, $answer);
                    $sent++;
                    $abandoned++;

                    continue;
                }
                $rename = $this->named($mutation, $response);
                // One step: the create leaves the queue and everything behind it
                // is renamed together, or neither happens.
                $this->outbox->acknowledged($mutation, $rename?->named, $this->references, $this->scopedBy);
                if ($rename !== null) {
                    $named[] = $rename;
                    // The scope being drained was this record's handle: what
                    // was queued under it now lives under its name.
                    if (($this->scopedBy[$type] ?? null) === $rename->handle->type && $rename->handle->id === $label) {
                        $label = $rename->named->id;
                    }
                }
                $sent++;

                continue;
            }

            if ($status === 'retry') {
                // Retrying is safe only under the same identity, so the queue
                // stays untouched and in order. Not counted as answered: a
                // gateway's JSON timeout lands here too, and a server's own
                // "busy" after a dropped connection may follow a COMMIT that
                // went through.
                return $wait($mutation, $response);
            }

            if ($status === 'unauthenticated') {
                // About the session, not the write. Abandoning here turned one
                // expired token into every queued write lost; the queue waits
                // for the user to sign in again instead. Answered before
                // anything ran: this sending did not land.
                $this->outbox->answered($mutation);

                return $wait($mutation, $response, true);
            }

            // Terminal for this identity. It leaves the queue rather than
            // blocking everything behind it forever, and the application has to
            // be told: nothing else will reveal that a write never landed.
            $this->outbox->abandon($mutation, $response->error() ?? ($response->status === 413 ? 'body_too_large' : 'rejected'));
            $abandoned++;
        }

        return new PushOutcome($sent, $abandoned, retryLater: false, outcomes: $outcomes, named: $named, rebased: $rebased);
    }

    /**
     * What has to be sent before this write: the deepest unsent create it
     * depends on, through its scope or a declared reference - or itself.
     * The flag is set when that dependency was abandoned, and names the write
     * that has to be abandoned with it.
     *
     * @param  list<string>  $seen  identities already on this path, so a cycle
     *                              of references ends instead of looping
     * @return array{0: Mutation, 1: string|null} the write, and why it cannot be sent if it cannot
     */
    private function nextToSend(Mutation $mutation, array $seen): array
    {
        $seen[] = $mutation->id;
        $parents = [];
        $scopeType = $this->scopedBy[$mutation->entity->type] ?? null;
        if ($scopeType !== null) {
            $parents[] = [$scopeType, $mutation->entity->space];
        }
        foreach ($this->references[$mutation->entity->type] ?? [] as $field => $target) {
            foreach ($mutation->operations as $operation) {
                $value = $operation->value->exists ? $operation->value->value() : null;
                if ($operation->field === $field && is_string($value)) {
                    $parents[] = [$target, $value];
                }
            }
        }

        // An edit queued before its record's create - a record created again
        // after its first create was refused: the create goes first.
        if ($mutation->kind !== MutationKind::Create) {
            $own = $this->outbox->queuedCreate($mutation->entity->type, $mutation->entity->id);
            if ($own !== null && $own->id !== $mutation->id && ! in_array($own->id, $seen, true)) {
                return $this->nextToSend($own, $seen);
            }
        }

        // A later write to a record whose own create was refused: the record
        // will not exist until the create is requeued, and sending the edit
        // now only loses it to entity_not_found.
        if ($mutation->kind !== MutationKind::Create
            && $this->outbox->queuedCreate($mutation->entity->type, $mutation->entity->id) === null
            && ($reason = $this->outbox->orphanReason($mutation->entity->type, $mutation->entity->id)) !== null) {
            return [$mutation, $reason];
        }

        foreach ($parents as [$parentType, $parentId]) {
            $create = $this->outbox->queuedCreate($parentType, $parentId);
            if ($create !== null && ! in_array($create->id, $seen, true)) {
                return $this->nextToSend($create, $seen);
            }
            if ($create === null && ($reason = $this->outbox->orphanReason($parentType, $parentId)) !== null) {
                return [$mutation, $reason];
            }
        }

        return [$mutation, null];
    }

    /**
     * Send an abandoned write again, under every name the server has given
     * since - the record, the scope it lives in, the records it points at.
     */
    public function requeue(string $mutationId, bool $evenIfItMayHaveLanded = false): ?Mutation
    {
        return $this->outbox->requeue($mutationId, $this->references, $this->scopedBy, $evenIfItMayHaveLanded);
    }

    /**
     * The application has told the user about an abandoned write; stop
     * reporting it. A dismissed create takes the writes that need its record
     * along with it - parent_abandoned, or parent_unknown when the create may
     * have landed - and returns how many, which are now abandoned in turn.
     */
    public function dismiss(string $mutationId): int
    {
        return $this->outbox->dismiss($mutationId, $this->references, $this->scopedBy);
    }

    /**
     * Put the rethought write in the queue in place of the refused one.
     *
     * Based on the version the refusal reported, never on anything pulled
     * since: a newer pull can include changes to fields the refusal did not
     * mention, and basing on it would overwrite them without anyone having
     * looked. If there are any, the server simply refuses again and names them.
     *
     * False when the refusal is not readable, which leaves the queue alone.
     */
    private function rebaseOnto(Mutation $mutation, SyncResponse $response, RebasePolicy $policy): bool
    {
        $version = $response->body->record_version ?? null;
        if (! is_int($version)) {
            return false;
        }

        /** @var array<string, ?FieldValue> $stale */
        $stale = [];
        foreach ((array) ($response->body->conflicts ?? []) as $conflict) {
            $field = is_object($conflict) ? ($conflict->field ?? null) : null;
            if (! is_string($field)) {
                return false;
            }
            $current = $conflict->current ?? null;
            $stale[$field] = $current instanceof \stdClass
                ? (($current->present ?? false) === true ? FieldValue::of($current->value ?? null) : FieldValue::missing())
                : null;
        }

        // Fields the resolver settled for the server are not this device's to
        // send again: rebased onto the reported version they would no longer
        // look like a conflict, and the device would win a field the host said
        // it must lose.
        $kept = [];
        foreach ((array) ($response->body->decisions ?? []) as $field => $decision) {
            if ($decision === ConflictDecision::Server->value) {
                $kept[(string) $field] = true;
            }
        }

        $operations = [];
        foreach ($mutation->operations as $operation) {
            if (isset($kept[$operation->field])) {
                continue;
            }
            if (! array_key_exists($operation->field, $stale)) {
                $operations[] = $operation;

                continue;
            }
            $choice = $policy->rebase(new StaleField(
                $mutation->entity->type,
                $mutation->entity->id,
                $operation->field,
                $operation->value,
                $stale[$operation->field],
            ));
            if ($choice->value !== null) {
                $operations[] = new FieldOperation($operation->field, $choice->value);
            }
        }

        $this->outbox->rebase($mutation, new RecordVersion($version), $operations);

        return true;
    }

    /**
     * A create went out under a handle the device made up, and the server
     * answered with the name it gave the record.
     *
     * Everything still queued behind that create refers to the handle and would
     * be a write to a record that does not exist, so the queue is renamed here.
     * The replica is untouched on purpose: it only ever holds records that came
     * back from the server, so it never knew the handle.
     */
    private function named(Mutation $mutation, SyncResponse $response): ?ValueObjects\RecordNamed
    {
        $name = $response->body->id ?? null;
        if (! is_string($name) || $name === '' || $name === $mutation->entity->id) {
            return null;
        }

        return new ValueObjects\RecordNamed($mutation->entity, new EntityKey($mutation->entity->space, $mutation->entity->type, $name));
    }

    private function outcome(Mutation $mutation, SyncResponse $response): ValueObjects\MutationOutcome
    {
        $groups = [];
        foreach ((array) ($response->body->conflict_groups ?? []) as $group) {
            $id = is_object($group) ? ($group->id ?? null) : $group;
            if (is_string($id)) {
                $groups[] = $id;
            }
        }
        $codes = [];
        foreach ((array) ($response->body->validation ?? []) as $failure) {
            $code = is_object($failure) ? ($failure->code ?? null) : null;
            if (is_string($code)) {
                $codes[] = $code;
            }
        }
        $reason = $response->body->reason ?? null;
        $status = $response->body->status ?? null;
        $decisions = [];
        foreach ((array) ($response->body->decisions ?? []) as $field => $decision) {
            $known = is_string($decision) ? ConflictDecision::tryFrom($decision) : null;
            if ($known !== null) {
                $decisions[(string) $field] = $known;
            }
        }

        return new ValueObjects\MutationOutcome(
            $mutation->id,
            $mutation->entity->type,
            $mutation->entity->id,
            // Already validated by protocolStatus() before we get here.
            MutationStatus::from(is_string($status) ? $status : ''),
            is_string($reason) ? $reason : null,
            $groups,
            $codes,
            $decisions,
        );
    }

    /**
     * Classify an answer, refusing to act on anything that is not recognisably
     * one of ours.
     *
     * Null means "no protocol answer": the body was not a sync response at all.
     * That has to be distinguishable from a rejection, because a gateway's HTML
     * 502 and a server's considered refusal call for opposite actions.
     */
    private function protocolStatus(SyncResponse $response): ?string
    {
        if ($response->status === 401) {
            // Whatever the body says - Laravel's own auth middleware answers
            // {"message": "Unauthenticated."}, with no error code at all.
            return 'unauthenticated';
        }
        if ($response->ok()) {
            $status = $response->body->status ?? null;
            if (! is_string($status) || MutationStatus::tryFrom($status) === null) {
                return null;
            }

            return match ($status) {
                MutationStatus::MutationGap->value => 'mutation_gap',
                MutationStatus::PullRequired->value => 'pull_required',
                MutationStatus::ReceiptPruned->value => 'receipt_pruned',
                default => 'processed',
            };
        }

        if ($response->status === 413) {
            // Too large, whoever says so - the server, or a proxy in front of it
            // answering in HTML. Sent again it is exactly as large.
            return 'terminal';
        }
        $error = $response->error();
        if ($error === null) {
            return null;
        }
        if ($response->retriable()) {
            return 'retry';
        }

        // Terminal only when the server named a refusal of THIS write. A JSON
        // body with some "error" key is not enough: a gateway's 502, a rate
        // limiter's 429 and a proxy's own error pages speak JSON too, and
        // treating those as final drained the queue on the first hiccup.
        // Anything unrecognised is left in place to be tried again.
        return $response->status >= 400 && $response->status < 500 && $response->status !== 429 && in_array($error, self::TERMINAL, true)
            ? 'terminal'
            : 'retry';
    }

    /**
     * Bring one view up to date: finish any bootstrap, then follow deltas.
     *
     * A reset is honoured rather than retried. It means this device's local
     * state for that view can no longer be trusted, so continuing to apply
     * pages onto it would be building on sand. Local knowledge for the view is
     * dropped and the view is rebuilt from a fresh bootstrap.
     *
     * The rebuild is attempted exactly once. A second reset means the server
     * changed the view again while this device was rebuilding it, and retrying
     * in a loop would spin against a moving target rather than letting the
     * application decide to back off.
     *
     * @return bool whether the view is now caught up - false when the server did not answer, or asked to come back later
     */
    public function pull(string $type, ?string $scope = null, ?int $pageSize = null): bool
    {
        $pageSize ??= $this->pageSize;
        try {
            return $this->follow($type, $scope, $pageSize);
        } catch (Exceptions\SyncRequestFailed $failed) {
            if (! $failed->requiresReset()) {
                throw $failed;
            }

            $this->resetView($type, $scope);

            return $this->follow($type, $scope, $pageSize);
        }
    }

    /**
     * Forget everything this device knows about one view.
     *
     * The replica is reset before the index entry is dropped. The other order
     * loses the fingerprint that finds the context, and the memberships that
     * context names are then unreachable for good.
     */
    private function resetView(string $type, ?string $scope): void
    {
        $fingerprint = $this->views->fingerprint($type, $scope);
        if ($fingerprint !== null) {
            $context = $this->replica->contextFor($fingerprint);
            if ($context !== null) {
                $this->replica->resetView($context);
            }
        }

        $this->views->forget($type, $scope);
    }

    private function follow(string $type, ?string $scope, int $pageSize): bool
    {
        $request = ['type' => $type, 'scope' => $scope];
        $cursor = $this->resume($type, $scope);

        if ($cursor === null) {
            // A bootstrap interrupted part-way is resumed with the token the
            // replica is waiting for. Opening a fresh one would produce a page
            // it refuses as out of order, every time, with no way back.
            $token = $this->pendingToken($type, $scope);
            do {
                $response = $this->transport->post('bootstrap', $request + ['page_size' => $pageSize] + ($token === null ? [] : ['token' => $token]));
                if (! $this->guard($response)) {
                    return false;
                }
                $page = Wire::bootstrapPage($response->body, new BootstrapToken(Support\Read::string($response->body, 'token')));
                // Remembered BEFORE the page is applied. The two are separate
                // commits, and the other order leaves a crash between them
                // with a replica expecting the next page of a bootstrap this
                // device no longer knows it started - every later pull then
                // opens a fresh one and is refused as out of order, for good.
                // This way round a crash leaves a fingerprint with no context
                // behind it, which simply starts the bootstrap again.
                $this->views->remember($type, $scope, $page->context->fingerprint());
                $this->replica->applyBootstrap($page);
                $token = $page->nextToken?->value;
                $cursor = $page->cursor;
            } while ($token !== null);
        }

        while ($cursor instanceof ViewCursor) {
            $response = $this->transport->post('delta', $request + ['cursor' => ['position' => $cursor->position->value, 'context' => $cursor->context->fingerprint()]]);
            if (! $this->guard($response)) {
                return false;
            }
            $delta = Wire::deltaPage($response->body);
            $this->replica->applyDelta($delta);
            $cursor = $delta->hasMore ? $delta->cursor : null;
        }

        return true;
    }

    /** The continuation token an interrupted bootstrap left behind, if any. */
    private function pendingToken(string $type, ?string $scope): ?string
    {
        $fingerprint = $this->views->fingerprint($type, $scope);
        if ($fingerprint === null) {
            return null;
        }
        $context = $this->replica->contextFor($fingerprint);

        return $context === null ? null : $this->replica->pendingBootstrapToken($context)?->value;
    }

    /**
     * Start a view again only when this device has never finished one.
     *
     * Reopening a bootstrap for a view already in delta is refused by the
     * replica, and rightly so: it would rewind local state that is already
     * ahead of the page being offered.
     */
    private function resume(string $type, ?string $scope): ?ViewCursor
    {
        $fingerprint = $this->views->fingerprint($type, $scope);
        if ($fingerprint === null) {
            return null;
        }
        $context = $this->replica->contextFor($fingerprint);

        return $context === null ? null : $this->replica->cursor($context);
    }

    /**
     * A refusal is raised; a non-answer is not.
     *
     * An HTML error page, a proxy timeout, a dead socket - none of those is the
     * server saying anything, and push() has always treated them as "leave it
     * and come back". Reading did not: any non-2xx threw, so a dropped network
     * during a pull became an uncaught exception out of the caller's scheduler,
     * which is the same defect the transport itself had.
     *
     * Only a considered refusal - one that names an error - is worth raising,
     * because only that tells the caller something it can act on.
     */
    /** A redirect: never followed, so the configured URL is not the server's final one. */
    private static function redirected(SyncResponse $response): bool
    {
        return $response->status >= 300 && $response->status < 400;
    }

    private function guard(SyncResponse $response): bool
    {
        if ($response->ok()) {
            return true;
        }
        if ($response->status === 401) {
            // Laravel's own auth middleware answers with no error code, and
            // taking that for a non-answer left a device with an expired
            // session reporting all clear while it stopped receiving anything.
            throw new Exceptions\SyncRequestFailed('unauthenticated', 401);
        }
        if (self::redirected($response)) {
            // Never followed - it would carry the device's credentials along -
            // so a URL that only works through a redirect never works. Said
            // by name, not left as silence.
            throw new Exceptions\SyncRequestFailed('redirected', $response->status, 'sync-client.url must be the final https URL of the server');
        }
        if ($response->error() === null || $response->retriable()) {
            // Not an answer, or "come back later": the view stays where it
            // is, and the next pull carries on from there.
            return false;
        }

        throw new Exceptions\SyncRequestFailed(
            $response->error(),
            $response->status,
            $response->reason(),
        );
    }
}
