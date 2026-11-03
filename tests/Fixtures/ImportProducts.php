<?php

declare(strict_types=1);

namespace Larasell\Chronicle\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Larasell\Chronicle\Facades\Chronicle;
use RuntimeException;

class ImportProducts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  'import'|'fail'|'release'|'dispatch'  $behavior
     */
    public function __construct(
        public string $behavior = 'import',
    ) {}

    public function handle(): void
    {
        Chronicle::add('import.behavior', $this->behavior);

        if ($this->behavior === 'fail') {
            throw new RuntimeException('disk full');
        }

        if ($this->behavior === 'release') {
            $this->release(30);

            return;
        }

        if ($this->behavior === 'dispatch') {
            self::dispatch('import');
        }

        Chronicle::add('import.done', true);
    }
}
