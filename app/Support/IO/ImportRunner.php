<?php

namespace App\Support\IO;

/**
 * Turns a FILE into an ImportResult, for whoever is driving — a request or a queued
 * worker.
 *
 * It has two callers, ImportExportController and RunResourceImportJob, and a parser
 * that exists twice is a parser that will eventually disagree with itself about what a
 * .xlsx is. So it lives here once; the controller asks this class the same questions
 * the worker does.
 *
 * It deliberately does NOT know about import_runs, queues or progress rows. It parses,
 * routes to the right importer, and returns a result. Recording that result is the
 * caller's job, because a synchronous run records it once at the end and a queued run
 * records it as it goes.
 */
class ImportRunner
{
    public function __construct(private ImportProcessor $processor) {}

    /** Parse an import file at $path into header-keyed rows, by extension. */
    public function parse(string $path, string $extension): array
    {
        if (in_array(strtolower($extension), ['xlsx', 'xls'], true)) {
            return Spreadsheet::parseAssoc($path);
        }

        return Csv::parseAssoc(file_get_contents($path));
    }

    /**
     * True when this upload is a JSON document for a schema that accepts one. Those
     * never queue — see runJson().
     */
    public function isJson(ResourceSchema $schema, string $extension): bool
    {
        return strtolower($extension) === 'json' && $schema->jsonImporter() !== null;
    }

    /**
     * A whole-document JSON import. Stays SYNCHRONOUS regardless of the
     * row threshold: it is one document, not N rows, so there is no row count to
     * threshold on and no per-row progress to report. If a JSON source ever grows big
     * enough to time out, it needs its own progress contract, not this one pretending
     * to have counted something.
     */
    public function runJson(ResourceSchema $schema, string $path, bool $commit): ImportResult
    {
        $json = json_decode(file_get_contents($path), true);

        if (! is_array($json)) {
            return new ImportResult(errors: [['row' => 0, 'messages' => ['The file is not valid JSON.']]]);
        }

        try {
            return $schema->jsonImporter()->import($json, $commit);
        } catch (\Throwable $e) {
            report($e);

            return new ImportResult(errors: [['row' => 0, 'messages' => [$e->getMessage()]]]);
        }
    }

    /**
     * Run already-parsed rows through the resource's importer.
     *
     * A resource with a customImporter() (one that writes through a designated writer) gets no
     * progress callback: that interface is the module's own and this class may not
     * assume it has one. Such a run reports 0% until it finishes, which is honest —
     * nothing here can see inside it.
     */
    public function runRows(ResourceSchema $schema, array $rows, bool $commit, ?callable $onProgress = null): ImportResult
    {
        $importer = $schema->customImporter();

        if ($importer) {
            return $importer->import($rows, $commit);
        }

        return $this->processor->processRows($schema, $rows, $commit, $onProgress);
    }

    /** Whether a run of $rowCount rows should be handed to a worker. */
    public function shouldQueue(ResourceSchema $schema, int $rowCount): bool
    {
        $threshold = (int) config('io.queue_threshold', 200);

        // A threshold of 0 or less disables queuing entirely — the escape hatch for an
        // environment with no worker running (a small self-hosted install, or the test
        // suite asserting the synchronous path still behaves as it always did).
        if ($threshold < 1) {
            return false;
        }

        return $rowCount >= $threshold;
    }
}
