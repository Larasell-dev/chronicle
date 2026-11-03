<?php

declare(strict_types=1);

namespace Larasell\Chronicle\WideEvents\Jobs;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Larasell\Chronicle\Support\Assert;
use Larasell\Chronicle\WideEvents\Chronicle;
use Throwable;

/**
 * Records one event of type job for every attempt of a queued job, when chronicle.log_jobs is on.
 * A job's attempt begins with JobProcessing and ends with either JobProcessed or
 * JobExceptionOccurred, so a scope opens on the first and closes on the second, and Chronicle::add()
 * in between, e.g. in an action the job calls, adds to the job's event.
 *
 * @internal
 */
final class JobLogger
{
    /**
     * The attempts being logged, innermost last: a job can dispatch another one to the sync queue,
     * which then runs inside it.
     *
     * @var list<array{job: Job, depth: int, startedAtNanoseconds: int|float}>
     */
    private array $running = [];

    public function processing(JobProcessing $event): void
    {
        if (! config('chronicle.log_jobs')) {
            return;
        }

        $startedAtNanoseconds = hrtime(true);

        $this->running[] = [
            'job' => $event->job,
            'depth' => $this->chronicle()->beginScope(),
            'startedAtNanoseconds' => $startedAtNanoseconds,
        ];
    }

    public function processed(JobProcessed $event): void
    {
        $this->finish($event->job, $event->job->isReleased() ? 'released' : 'processed', null);
    }

    public function exceptionOccurred(JobExceptionOccurred $event): void
    {
        $this->finish($event->job, 'failed', $event->exception);
    }

    private function finish(Job $job, string $outcome, ?Throwable $exception): void
    {
        $index = $this->runningIndex($job);

        // Not logged: chronicle.log_jobs was off when it began, or its event is already written, and
        // the worker reports an exception that happened after that, e.g. in another listener.
        if ($index === null) {
            return;
        }

        $running = $this->running[$index];
        Assert::that(
            $index === array_key_last($this->running),
            'chronicle.assert.job-order',
            "The job {$job->resolveName()} ends while a job it dispatched is still running",
        );
        $this->running = array_slice($this->running, 0, $index);

        $this->chronicle()->endScope('job', $running['depth'], $running['startedAtNanoseconds'], [
            'job' => [
                'name' => $job->resolveName(),
                'id' => $job->uuid(),
                'queue' => $job->getQueue(),
                'connection' => $job->getConnectionName(),
                'attempt' => $job->attempts(),
                // The outcome of this attempt: a failed attempt may still be retried.
                'outcome' => $outcome,
            ],
        ], $exception);
    }

    private function runningIndex(Job $job): ?int
    {
        foreach ($this->running as $index => $running) {
            if ($running['job'] === $job) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Resolved on every job rather than injected, so Chronicle::fake() in a test takes effect.
     */
    private function chronicle(): Chronicle
    {
        return app(Chronicle::class);
    }
}
