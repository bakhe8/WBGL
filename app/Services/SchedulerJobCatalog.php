<?php
declare(strict_types=1);

namespace App\Services;

class SchedulerJobCatalog
{
    /**
     * @return array<int,array{name:string,path:string,max_attempts:int}>
     */
    public static function all(): array
    {
        return [];
    }

    /**
     * @return array{name:string,path:string,max_attempts:int}|null
     */
    public static function find(string $jobName): ?array
    {
        $jobName = trim($jobName);
        if ($jobName === '') {
            return null;
        }
        foreach (self::all() as $job) {
            if (($job['name'] ?? '') === $jobName) {
                return $job;
            }
        }
        return null;
    }
}
