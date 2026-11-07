<?php

namespace App\Jobs;

use App\Connectors\ConnectorException;
use App\Connectors\Connectors;
use App\Models\ConnectorRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Carries out one connector call (provision, suspend, resume, deprovision). Errors are retried with
 * growing delays (about 5 hours in total), permanent ones (configuration, rejected API key) not;
 * after the last attempt the run is failed and the admins get a mail.
 */
class RunConnector implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public ConnectorRun $run)
    {
        $this->afterCommit();
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800, 3600 * 3];
    }

    public function handle(Connectors $connectors): void
    {
        try {
            $connectors->execute($this->run);
        } catch (ConnectorException $exception) {
            $connectors->recordError($this->run, $exception);

            if (! $exception->permanent) {
                throw $exception;
            }

            $this->fail($exception);
        } catch (Throwable $exception) {
            $connectors->recordError($this->run, $exception);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(Connectors::class)->failed($this->run, $exception);
    }
}
