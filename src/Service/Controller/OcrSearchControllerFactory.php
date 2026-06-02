<?php

namespace Search\Service\Controller;

use Interop\Container\ContainerInterface;
use Search\Controller\OcrSearchController;
use Laminas\ServiceManager\Factory\FactoryInterface;

class OcrSearchControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        return new OcrSearchController(
            $services->get('Omeka\AuthenticationService')
        );
    }
}
