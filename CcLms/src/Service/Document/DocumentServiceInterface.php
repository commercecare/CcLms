<?php

namespace CcLms\Service\Document;

use Shopware\Core\Framework\Context;

/**
 * Interface DocumentServiceInterface
 * @package CcLms\Service\Document
 */
interface DocumentServiceInterface
{
    public function generate(array $orderIds, Context $context): ?array;
}