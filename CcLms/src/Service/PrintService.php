<?php declare(strict_types=1);

namespace CcLms\Service;

use Shopware\Core\Framework\Context;
use CcLms\Service\Document\DocumentServiceInterface;

/**
 * Class PrintService
 * @package CcLms\Service
 */
class PrintService implements PrintServiceInterface
{

    /**
     * @var DocumentServiceInterface
     */
    protected $invoiceDocumentService;

    /**
     * @var DocumentServiceInterface
     */
    protected $deliveryDocumentService;

    /**
     * @var DocumentServiceInterface
     */
    protected $dhlDocumentService;

    public function __construct(
        DocumentServiceInterface $invoiceDocumentService,
        DocumentServiceInterface $deliveryDocumentService,
        DocumentServiceInterface $dhlDocumentService
    )
    {
        $this->invoiceDocumentService = $invoiceDocumentService;
        $this->deliveryDocumentService = $deliveryDocumentService;
        $this->dhlDocumentService = $dhlDocumentService;
    }

    /**
     * @param string $action
     * @param array $orderIds
     * @param Context $context
     * @return String|null
     * @throws \Throwable
     */
    public function print(string $action, array $orderIds, Context $context): ?String
    {
        $documentService = $this->{$action . 'DocumentService'};
        if (!($documentService instanceof DocumentServiceInterface)) {
            return null;
        }
        $documents = $documentService->generate($orderIds, $context);
        if (empty($documents)) {
            return null;
        }
        $zipName = tempnam(sys_get_temp_dir(), $action);
        $zip = new \ZipArchive();
        if (!$zip->open($zipName, \ZipArchive::CREATE)) {
            return null;
        }
        foreach ($documents as $name => $document) {
            $zip->addFromString($name . '.pdf', $document);
        }
        $zip->close();
        $responseContent = file_get_contents($zipName);
        unlink($zipName);
        return $responseContent;
    }
}