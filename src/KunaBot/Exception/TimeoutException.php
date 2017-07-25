<?php

namespace madmis\KunaBot\Exception;

/**
 * Interface TimeoutException
 * @package madmis\KunaBot\Exception
 */
interface TimeoutException
{
    /**
     * @param int $timeout
     */
    public function setTimeout(int $timeout): void;

    /**
     * @return int
     */
    public function getTimeout(): int;
}
