<?php

namespace App\Migrations\Factory;

use Symfony\Component\DependencyInjection\ContainerInterface;

interface MigrationInterface
{
    public function setContainer(ContainerInterface $container = null);
}
