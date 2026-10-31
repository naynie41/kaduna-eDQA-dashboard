<?php

declare(strict_types=1);

// The historical backfill (ARCHITECTURE.md §5.6): edqa:backfill and edqa:backfill-report.

return [

    'refused' => [
        'no_historical_rounds' => 'No historical round exists yet. Create the rounds the submissions span first (run edqa:backfill {source} --survey to see them): a visit outside every round is quarantined, never guessed into one.',
        'already_running' => 'A backfill is already running.',
        'other_source_running' => 'A backfill of :source is still running. Backfill one source at a time: let it finish first.',
    ],

    'survey' => [
        'title' => 'Survey: :total submission(s) in :source, read without writing anything.',
        'quarter' => 'Quarter',
        'version' => 'Form version',
        'mapped' => 'Field map',
        'round' => 'Round',
        'submissions' => 'Submissions',
        'yes' => 'yes',
        'no' => 'NO',
        'round_none' => 'none',
        'round_open' => 'open',
        'round_closed' => 'closed',
        'create_rounds' => 'No round yet for these quarters (submissions in each): :quarters. Create the rounds these visits belong to before the backfill; a visit in a quarter left without one quarantines under ROUND_WINDOW.',
        'closed_rounds' => 'These rounds are closed, so their visits would quarantine as "round closed": reopen them for the backfill, then close them again: :quarters.',
        'unmapped' => 'No field map for :versions: those submissions will quarantine under UNKNOWN_FORM_VERSION.',
        'ready' => 'Every quarter has an open round.',
    ],

    'run' => [
        'page' => 'Page :page: :position of :total stored · accepted :accepted, flagged :flagged, quarantined :quarantined, already stored :duplicates.',
        'done' => 'Backfill finished: :fetched read, :duplicates already stored, :accepted accepted, :flagged flagged, :quarantined quarantined. Attachments are recorded: run edqa:attachments:fetch-pending to fetch them. Then edqa:backfill-report.',
        'failed' => 'Backfill stopped: :error. Run edqa:backfill again to resume from the last committed page.',
    ],

    'report' => [
        'written' => 'Backfill report written to :path.',
        'bad_format' => 'Give --format as one of: :formats.',
        'title' => 'Backfill report',
        'generated' => 'Generated :at. Every stored submission, from every source, as it stands now.',
        'totals' => ':submissions submission(s) stored, of which :superseded replaced by an edit (counted once, apart, below).',
        'all_sources' => 'All sources',
        'source' => 'Source',
        'duplicates' => 'Duplicates between the sources',
        'duplicates_note' => 'When both sources hold the same quarters, decide which copy is authoritative (Q-15). DUPLICATE_ASSESSMENT: open records of a second visit to a facility in a round; of which the first came from the other source. Cross-source duplicates: the same submission (instance ID) sent by the other source too, counted by every run and never stored twice.',
        'against_other_source' => 'Of which against the other source',
        'cross_source_duplicates' => 'Cross-source duplicates',
        'by_round' => 'Submissions per round',
        'round' => 'Round',
        'no_round' => 'No round (outside every window, or not processed)',
        'pulled' => 'Pulled',
        'accepted' => 'Accepted',
        'flagged' => 'Flagged',
        'quarantined' => 'Quarantined',
        'rejected' => 'Rejected',
        'unprocessed' => 'Not processed',
        'total' => 'Total',
        'superseded' => 'Superseded by an edit',
        'by_rule_year' => 'Quarantined, by rule and year of the visit',
        'by_rule_version' => 'Quarantined, by rule and form version',
        'rule_note' => 'Open quarantine records. A record that failed several rules counts under each.',
        'rule' => 'Rule',
        'year' => 'Year',
        'version' => 'Form version',
        'records' => 'Records',
        'examples' => 'Most frequent details, per rule',
        'detail' => 'Detail',
        'none' => 'None.',
    ],

];
