<?php declare(strict_types=1);

namespace CcLms\Service\Document;

use Shopware\Core\Checkout\Document\DocumentGenerator\InvoiceGenerator;
use Shopware\Core\Checkout\Document\DocumentService;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;
use Shopware\Core\Checkout\Document\DocumentEntity;

/**
 * Class InvoiceService
 * @package CcLms\Service\Document
 */
class InvoiceService implements DocumentServiceInterface
{

    /**
     * @var DocumentService
     */
    protected $documentService;

    /**
     * @var EntityRepositoryInterface
     */
    private $documentRepository;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * InvoiceService constructor.
     * @param DocumentService $documentService
     * @param EntityRepositoryInterface $documentRepository
     * @param Connection $connection
     */
    public function __construct(
        DocumentService $documentService,
        EntityRepositoryInterface $documentRepository,
        Connection $connection
    )
    {
        $this->documentService = $documentService;
        $this->documentRepository = $documentRepository;
        $this->connection = $connection;
    }

    /**
     * @param array $orderIds
     * @param Context $context
     * @return array|null
     * @throws \Throwable
     */
    public function generate(array $orderIds, Context $context): ?array
    {
        $documentIds = $this->getDocumentIds($orderIds, $context);
        $criteria = new Criteria();
        $criteria->addFilter(new Filter\EqualsAnyFilter('id', array_keys($documentIds)));
        $criteria->addAssociation('documentMediaFile');
        $criteria->addAssociation('documentType');

        $documents = $this->documentRepository->search($criteria, $context);
        $data = [];
        foreach ($documents as $document) {
            if ($document instanceof DocumentEntity) {
                $generatedDocument = $this->documentService->getDocument($document, $context);
                $data[$documentIds[$document->getId()]['order_number'] . '_' . explode('.', $generatedDocument->getFilename())[0]] = $generatedDocument->getFileBlob();
            }
        }
        return $data;
    }

    /**
     * @param array $orderIds
     * @param Context $context
     * @return mixed
     */
    protected function getDocumentIds(array $orderIds, Context $context): array
    {
        $query = new QueryBuilder($this->connection);
        $expr = $query->expr();
        $query->select('d.id', 'o.order_number')
            ->from('document', 'd')
            ->innerJoin('d', '`order`', 'o', 'd.order_id=o.id')
            ->innerJoin('d', 'document_type', 'dt', 'd.document_type_id=dt.id')
            ->where($expr->in('d.order_id', ':orderIds'))
            ->andWhere($expr->eq('dt.technical_name', ':docTypeTechnicalName'))
//            ->andWhere($expr->eq('d.order_version_id', ':versionId'))
            ->setParameter('orderIds', Uuid::fromHexToBytesList($orderIds), Connection::PARAM_STR_ARRAY)
            ->setParameter('docTypeTechnicalName', InvoiceGenerator::INVOICE)
//            ->setParameter('versionId', Uuid::fromHexToBytes($context->getVersionId()))
            ->groupBy('d.order_id')
            ->orderBy('d.created_at', 'DESC');
        $result = $query->execute()->fetchAll();
        if (empty($result)) {
            return [];
        }
        $result = array_map(function ($data) {
            $data['id'] = Uuid::fromBytesToHex($data['id']);
            return $data;
        }, $result);
        return array_combine(array_column($result, 'id'), $result);
    }
}