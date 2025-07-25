<?php
/**
 * @license GPL-3.0
 *
 * Modified by Team Caseproof using {@see https://github.com/BrianHenryIE/strauss}.
 */

declare(strict_types=1);

namespace memberpress\courses\GroundLevel\Container\Concerns;

use memberpress\courses\GroundLevel\Container\Container;
use memberpress\courses\GroundLevel\Container\Contracts\ContainerAwareness;

trait HasContainer
{
    /**
     * The container instance.
     *
     * @var Container
     */
    protected Container $container;

    /**
     * Retrieves a container.
     *
     * @return Container
     */
    public function getContainer(): Container
    {
        return $this->container;
    }

    /**
     * Sets a container.
     *
     * @param  Container $container The container.
     * @return ContainerAwareness
     */
    public function setContainer(Container $container): ContainerAwareness
    {
        $this->container = $container;
        return $this;
    }
}
