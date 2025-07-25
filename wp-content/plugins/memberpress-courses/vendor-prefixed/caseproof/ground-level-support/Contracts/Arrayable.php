<?php
/**
 * @license GPL-3.0
 *
 * Modified by Team Caseproof using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace memberpress\courses\GroundLevel\Support\Contracts;

interface Arrayable
{
    /**
     * Retrieves the instance as an array.
     *
     * @return array
     */
    public function toArray(): array;
}
