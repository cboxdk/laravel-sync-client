<?php

declare(strict_types=1);

use Cbox\Sync\Client\Laravel\Rebase\KeepMine;
use Cbox\Sync\Client\Laravel\Rebase\TakeTheirs;
use Cbox\Sync\Client\Laravel\Rebase\Using;
use Cbox\Sync\Client\Laravel\SyncClient;
use Cbox\Sync\Client\Laravel\ValueObjects\RebaseChoice;
use Cbox\Sync\Client\Laravel\ValueObjects\StaleField;
use Cbox\Sync\Contracts\ConflictResolver;
use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\ConflictContext;
use Cbox\Sync\Data\FieldOperation as Op;
use Cbox\Sync\Enums\ConflictDecision;
use Cbox\Sync\Enums\MutationKind;
use Cbox\Sync\Enums\MutationStatus;
use Cbox\Sync\Resolvers\ServerWins;
use Cbox\Sync\ValueObjects\EntityKey;

/**
 * Alice creates a task and both devices learn it. Returns its real key.
 *
 * @return array{0: SyncClient, 1: SyncClient, 2: EntityKey}
 */
function sharedTask(object $test): array
{
    $alice = $test->syncClientAs('alice');
    $alice->outbox()->queue(new EntityKey('team-1', 'tasks', 'h1'), MutationKind::Create, [Op::set('title', 'draft'), Op::set('status', 'open')], 0);
    $named = $alice->sync('tasks', 'team-1')->named[0]->named;

    $bob = $test->secondDeviceFor('alice', 'device-2');
    $bob->sync('tasks', 'team-1');

    return [$alice, $bob, $named];
}

/** Bob changes the title first; Alice, offline, edited the title and the status against version 1. */
function raced(SyncClient $alice, SyncClient $bob, EntityKey $key, string $theirs = 'from bob'): void
{
    $alice->outbox()->queue($key, MutationKind::Update, [Op::set('title', 'from alice'), Op::set('meta', 'doing')], 1);
    $bob->outbox()->queue($key, MutationKind::Update, [Op::set('title', $theirs)], 1);
    $bob->sync('tasks', 'team-1');
}

it('keeps this device\'s edit as an informed write, with no conflict left behind', function () {
    [$alice, $bob, $key] = sharedTask($this);
    raced($alice, $bob, $key);

    $outcome = $alice->rebaseWith(new KeepMine)->sync('tasks', 'team-1');

    expect($outcome->rebased)->toBe(1)
        ->and($outcome->needingAttention())->toBe([])
        ->and($outcome->outcomes[0]->status)->toBe(MutationStatus::Applied)
        ->and($alice->replica()->record($key)?->value('title')->value())->toBe('from alice')
        ->and($alice->replica()->record($key)?->value('meta')->value())->toBe('doing')
        ->and(app(Store::class)->openGroups($key))->toBe([]);
});

it('drops only the contested field when the other edit wins', function () {
    [$alice, $bob, $key] = sharedTask($this);
    raced($alice, $bob, $key);

    $outcome = $alice->rebaseWith(new TakeTheirs)->sync('tasks', 'team-1');

    expect($outcome->rebased)->toBe(1)
        ->and($alice->replica()->record($key)?->value('title')->value())->toBe('from bob')
        // The field nobody else touched still lands.
        ->and($alice->replica()->record($key)?->value('meta')->value())->toBe('doing')
        ->and(app(Store::class)->openGroups($key))->toBe([]);
});

it('hands the policy both values so it can merge them', function () {
    [$alice, $bob, $key] = sharedTask($this);
    raced($alice, $bob, $key);
    $asked = [];

    $alice->rebaseWith(new Using(function (StaleField $field) use (&$asked): RebaseChoice {
        $asked[] = $field->field;

        return RebaseChoice::use($field->theirs?->value().' + '.$field->mine->value());
    }))->sync('tasks', 'team-1');

    // Asked about the contested field only, never the one only Alice changed.
    expect($asked)->toBe(['title'])
        ->and($alice->replica()->record($key)?->value('title')->value())->toBe('from bob + from alice');
});

it('leaves the server to decide when nobody configured a policy', function () {
    [$alice, $bob, $key] = sharedTask($this);
    raced($alice, $bob, $key);

    $outcome = $alice->sync('tasks', 'team-1');

    expect($outcome->rebased)->toBe(0)
        ->and($outcome->outcomes[0]->status)->toBe(MutationStatus::Conflict)
        ->and(app(Store::class)->openGroups($key))->toHaveCount(1);
});

/**
 * A record someone keeps writing. Rethinking forever would race it forever;
 * after a bounded number of tries the server keeps both values instead, which
 * loses nothing.
 */
it('stops rethinking a write that keeps losing the race, and lets the server keep both', function () {
    [$alice, $bob, $key] = sharedTask($this);
    raced($alice, $bob, $key);
    $round = 0;

    $outcome = $alice->rebaseWith(new Using(function (StaleField $field) use ($bob, $key, &$round): RebaseChoice {
        // Bob writes again every time Alice has just caught up.
        $bob->outbox()->queue($key, MutationKind::Update, [Op::set('title', 'bob '.++$round)], $bob->replica()->record($key)?->version->value ?? 1);
        $bob->push('tasks', 'team-1');
        $bob->pull('tasks', 'team-1');

        return RebaseChoice::keepMine($field);
    }))->sync('tasks', 'team-1');

    expect($outcome->rebased)->toBe(SyncClient::REBASE_ATTEMPTS)
        ->and($outcome->outcomes[0]->status)->toBe(MutationStatus::Conflict)
        ->and($this->outbox()->pending())->toBe(0)
        ->and(app(Store::class)->openGroups($key))->toHaveCount(1);
});

it('survives the device restarting between the refusal and the retry', function () {
    [$alice, $bob, $key] = sharedTask($this);
    raced($alice, $bob, $key);

    // A refusal changes nothing on either side until the rebased write is
    // stored, so dying in between costs a round trip, not the edit.
    $alice->rebaseWith(new Using(function (StaleField $field): RebaseChoice {
        throw new RuntimeException('process died');
    }));
    expect(fn () => $alice->push('tasks', 'team-1'))->toThrow(RuntimeException::class);
    expect($this->outbox()->pending())->toBe(1);

    $this->restartDevice();
    $outcome = $this->syncClientAs('alice')->rebaseWith(new KeepMine)->sync('tasks', 'team-1');

    expect($outcome->outcomes[0]->status)->toBe(MutationStatus::Applied)
        ->and(app(Store::class)->record($key)?->value('title')->value())->toBe('from alice');
});

/**
 * A resolver that keeps the server's value answers noop. "Noop" reading as
 * "saved" told the user their edit stuck when it had been thrown away.
 */
it('does not report a write the server overrode as applied', function () {
    $this->app->instance(ConflictResolver::class, new ServerWins);
    [$alice, $bob, $key] = sharedTask($this);
    raced($alice, $bob, $key);

    $outcome = $alice->sync('tasks', 'team-1');
    $result = $outcome->outcomes[0];

    expect($result->overridden())->toBe(['title'])
        ->and($result->applied())->toBeFalse()
        ->and($outcome->needingAttention())->toHaveCount(1);
});

/**
 * A field the resolver kept for the server is settled, not stale. Rebased onto
 * the reported version it would no longer look like a conflict, and the device
 * would win a field the host said it must lose.
 */
it('never takes back a field the server kept', function () {
    $this->app->instance(ConflictResolver::class, new class implements ConflictResolver
    {
        public function resolve(ConflictContext $context): ConflictDecision
        {
            return $context->operation->field === 'meta' ? ConflictDecision::Server : ConflictDecision::Preserve;
        }
    });
    [$alice, $bob, $key] = sharedTask($this);
    $alice->outbox()->queue($key, MutationKind::Update, [Op::set('title', 'alice-title'), Op::set('meta', 'alice-meta')], 1);
    $bob->outbox()->queue($key, MutationKind::Update, [Op::set('title', 'bob-title'), Op::set('meta', 'bob-meta')], 1);
    $bob->sync('tasks', 'team-1');

    $alice->rebaseWith(new KeepMine)->sync('tasks', 'team-1');

    expect(app(Store::class)->record($key)?->value('meta')->value())->toBe('bob-meta')
        ->and(app(Store::class)->record($key)?->value('title')->value())->toBe('alice-title');
});

/** Queueing an edit on what record() returns is the natural next step; it has to reach the server. */
it('hands back a record that can be edited and pushed under its scope', function () {
    [$alice, , $key] = sharedTask($this);
    $record = $alice->record('tasks', 'team-1', $key->id) ?? throw new LogicException('expected the record');

    $alice->outbox()->queue($record->entity, MutationKind::Update, [Op::set('title', 'edited')], $record->version->value);

    expect($alice->sync('tasks', 'team-1')->sent)->toBe(1)
        ->and(app(Store::class)->record($key)?->value('title')->value())->toBe('edited');
});
