<?php
namespace VideoThumbnail\Job\DispatchStrategy;

use Omeka\Job\DispatchStrategy\StrategyInterface;
use Omeka\Entity\Job;

class VideoThumbnailStrategy implements StrategyInterface
{
    /**
     * Start a new job process.
     *
     * @param Job $job
     */
    public function start(Job $job)
    {
        $this->dispatchJob($job);
    }

    /**
     * Dispatch a job to be processed.
     *
     * @param Job $job
     */
    public function dispatchJob(Job $job)
    {
        try {
            // Get the job ID
            $jobId = $job->getId();

            // Set the job status
            $job->setStatus(Job::STATUS_STARTING);

            // Log that we've dispatched the job
            error_log(sprintf('VideoThumbnail job %s queued for background processing', $jobId));
        } catch (\Exception $e) {
            // Log the exception
            error_log(sprintf('Error dispatching VideoThumbnail job: %s', $e->getMessage()));
            error_log($e->getTraceAsString());

            // Optionally, you can rethrow the exception or handle it as needed
            throw $e;
        }
    }
}
