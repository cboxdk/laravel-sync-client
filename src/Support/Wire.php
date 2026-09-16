<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel\Support;

use Cbox\Sync\Data\EntityRecord;
use Cbox\Sync\Data\FieldState;
use Cbox\Sync\Data\Mutation;
use Cbox\Sync\ValueObjects\CommitSequence;
use Cbox\Sync\ValueObjects\EntityKey;
use Cbox\Sync\ValueObjects\FieldValue;
use Cbox\Sync\ValueObjects\RecordVersion;
use Cbox\Sync\Views\BootstrapPage;
use Cbox\Sync\Views\BootstrapToken;
use Cbox\Sync\Views\CursorContext;
use Cbox\Sync\Views\DeltaPage;
use Cbox\Sync\Views\ViewChange;
use Cbox\Sync\Views\ViewChangeKind;
use Cbox\Sync\Views\ViewCommit;
use Cbox\Sync\Views\ViewCursor;

/** Turns the transport's JSON into the engine's own types, and a mutation back into JSON. */
class Wire
{
    /** @return array<string, mixed> */
    public static function mutationToWire(Mutation $mutation): array
    {
        $operations = [];
        foreach ($mutation->operations as $operation) {
            // The operation kind carries the distinction, never the value: a
            // field set to null and a field with no value both encode as null.
            $operations[] = $operation->value->exists
                ? ['field' => $operation->field, 'op' => 'set', 'value' => $operation->value->value()]
                : ['field' => $operation->field, 'op' => 'unset'];
        }

        $body = [
            'mutation_id' => $mutation->id,
            'id' => $mutation->entity->id,
            'replica' => $mutation->replica->id,
            'sequence' => $mutation->sequence->value,
            'kind' => $mutation->kind->value,
            'base_version' => $mutation->baseVersion->value,
            'atomic' => $mutation->atomic,
            'operations' => $operations,
        ];
        if ($mutation->expectedVersion !== null) {
            $body['expected_version'] = $mutation->expectedVersion->value;
        }
        if ($mutation->resolution !== null) {
            $body['resolution'] = [
                'group_id' => $mutation->resolution->groupId,
                'group_revision' => $mutation->resolution->groupRevision,
                'candidate_ids' => $mutation->resolution->candidateIds,
            ];
        }

        return $body;
    }

    /** @param array<array-key, mixed> $body */
    public static function context(array $body): CursorContext
    {
        $context = Read::array($body, 'context');

        return new CursorContext(
            Read::string($context, 'space'),
            Read::string($context, 'view_id'),
            Read::string($context, 'filter_version'),
            Read::string($context, 'filter_signature'),
            Read::string($context, 'schema_version'),
            Read::string($context, 'epoch'),
        );
    }

    /** @param array<array-key, mixed> $body */
    public static function bootstrapPage(array $body, BootstrapToken $token): BootstrapPage
    {
        $context = self::context($body);
        $records = [];
        foreach (Read::list($body, 'records') as $record) {
            $records[] = self::record($record, $context->space);
        }
        $next = Read::optionalString($body, 'next_token');
        $cursor = $body['cursor'] ?? null;

        return new BootstrapPage(
            $records,
            $next === null ? null : new BootstrapToken($next),
            is_array($cursor) ? self::cursor($cursor, $context) : null,
            $context,
            $token,
            Read::int($body, 'offset'),
        );
    }

    /** @param array<array-key, mixed> $body */
    public static function deltaPage(array $body): DeltaPage
    {
        $context = self::context($body);
        $commits = [];
        foreach (Read::list($body, 'commits') as $commit) {
            $changes = [];
            foreach (Read::list($commit, 'changes') as $change) {
                $changes[] = self::change($change, $context->space);
            }
            $commits[] = new ViewCommit(new CommitSequence(Read::int($commit, 'sequence')), $changes);
        }

        return new DeltaPage(
            $commits,
            self::cursor(Read::array($body, 'previous_cursor'), $context),
            self::cursor(Read::array($body, 'cursor'), $context),
            Read::bool($body, 'has_more'),
        );
    }

    /** @param array<array-key, mixed> $wire */
    private static function cursor(array $wire, CursorContext $context): ViewCursor
    {
        return new ViewCursor($context, new CommitSequence(Read::int($wire, 'position')));
    }

    /** @param array<array-key, mixed> $wire */
    private static function change(array $wire, string $space): ViewChange
    {
        $kind = ViewChangeKind::from(Read::string($wire, 'kind'));
        $entity = new EntityKey($space, Read::string($wire, 'type'), Read::string($wire, 'id'));
        $version = new RecordVersion(Read::int($wire, 'version'));
        $record = $wire['record'] ?? null;

        return new ViewChange(
            Read::int($wire, 'ordinal'),
            $kind,
            $entity,
            $version,
            is_array($record) ? self::record($record, $space) : null,
        );
    }

    /** @param array<array-key, mixed> $wire */
    private static function record(array $wire, string $space): EntityRecord
    {
        $fields = [];
        foreach (Read::array($wire, 'fields') as $name => $state) {
            if (! is_array($state)) {
                throw new \UnexpectedValueException('Malformed field state in response');
            }
            // No field version and no origin: a projection withholds both, and
            // a client acts on neither.
            $fields[(string) $name] = new FieldState(
                ($state['present'] ?? false) === true ? FieldValue::of($state['value'] ?? null) : FieldValue::missing(),
            );
        }

        return new EntityRecord(
            new EntityKey($space, Read::string($wire, 'type'), Read::string($wire, 'id')),
            new RecordVersion(Read::int($wire, 'version')),
            $fields,
        );
    }
}
