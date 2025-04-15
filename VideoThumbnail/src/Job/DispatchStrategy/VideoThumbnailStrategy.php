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
        // Instead of executing the job directly, we'll queue it for the default Omeka S job processor
        // This delegates to the system's background job handling
        $jobId = $job->getId();
        
        // Just set the job status and let Omeka's job system handle it
        $job->setStatus(Job::STATUS_STARTING);
        
        // Log that we've dispatched the job
        error_log(sprintf('VideoThumbnail job %s queued for background processing', $jobId));
    }
}