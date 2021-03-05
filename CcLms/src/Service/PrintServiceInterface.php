<?php

namespace CcLms\Service;

use Shopware\Core\Framework\Context;

/**
 * Interface PrintServiceInterface
 * @package CcLms\Service
 */
interface PrintServiceInterface
{
    public function print(string $action, array $orderIds, Context $context): ?String;
}