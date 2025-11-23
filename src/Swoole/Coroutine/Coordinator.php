<?php

namespace Laravel\Octane\Swoole\Coroutine;

use Swoole\Coroutine\Channel;

/**
 * Coroutine Coordinator
 * 
 * Inspired by Hyperf's Coordinator pattern for managing coroutine lifecycle
 * and enabling graceful shutdown. Allows coroutines to wait for specific
 * events before proceeding.
 */
class Coordinator
{
    /**
     * Channel for coordination
     */
    private Channel $channel;
    
    /**
     * Whether the coordinator has been resumed
     */
    private bool $resumed = false;
    
    /**
     * Create a new coordinator instance
     */
    public function __construct()
    {
        $this->channel = new Channel(1);
    }
    
    /**
     * Wait until coordinator is resumed
     *
     * @param  float  $timeout  Timeout in seconds (-1 for no timeout)
     * @return bool True if resumed, false if timed out
     */
    public function yield(float $timeout = -1): bool
    {
        if ($this->resumed) {
            return true;
        }
        
        return $this->channel->pop($timeout) !== false;
    }
    
    /**
     * Resume all waiting coroutines
     *
     * @return void
     */
    public function resume(): void
    {
        if ($this->resumed) {
            return;
        }
        
        $this->resumed = true;
        
        // Push to channel to wake up waiting coroutines
        // Using errSilent to avoid "channel is full" errors
        $this->channel->push(true);
    }
    
    /**
     * Check if coordinator has been resumed
     *
     * @return bool
     */
    public function isResumed(): bool
    {
        return $this->resumed;
    }
    
    /**
     * Reset the coordinator (mainly for testing)
     *
     * @return void
     */
    public function reset(): void
    {
        $this->resumed = false;
        
        // Drain the channel
        while ($this->channel->length() > 0) {
            $this->channel->pop(0.001);
        }
    }
}
