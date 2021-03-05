<?php declare(strict_types=1);

namespace CcLms\Service\Document;

use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\Checkout\Order\SalesChannel\OrderService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Pickware\PickwareDhl\Shipment\ShipmentService;
use Pickware\ShopwarePlugins\DocumentBundle\DocumentContentsService;
use Pickware\PickwareDhl\Carrier\Model\CarrierEntity;
use Pickware\ShopwarePlugins\DocumentBundle\Model\DocumentEntity;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Shopware\Core\Framework\DataAbstractionLayer\Dbal\QueryBuilder;

/**
 * Class DhlService
 * @package CcLms\Service\Document
 */
class DhlService implements DocumentServiceInterface
{

    /**
     * @var string
     */
    const DOCUMENT_TYPE_TECHNICAL_NAME_SHIPPING_LABEL = 'shipping_label';

    /**
     * @var EntityRepositoryInterface
     */
    private $pickwareDhlCarrierRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $pickwareDocumentRepository;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var OrderService
     */
    private $orderService;

    /**
     * @var ShipmentService
     */
    private $shipmentService;

    /**
     * @var DocumentContentsService
     */
    private $documentContentsService;

    /**
     * DhlService constructor.
     * @param EntityRepositoryInterface $pickwareDhlCarrierRepository
     * @param EntityRepositoryInterface $pickwareDocumentRepository
     * @param Connection $connection
     * @param OrderService $orderService
     * @param ShipmentService $shipmentService
     * @param DocumentContentsService $documentContentsService
     */
    public function __construct(
        EntityRepositoryInterface $pickwareDhlCarrierRepository,
        EntityRepositoryInterface $pickwareDocumentRepository,
        Connection $connection,
        OrderService $orderService,
        ShipmentService $shipmentService,
        DocumentContentsService $documentContentsService
    )
    {
        $this->pickwareDhlCarrierRepository = $pickwareDhlCarrierRepository;
        $this->pickwareDocumentRepository = $pickwareDocumentRepository;
        $this->connection = $connection;
        $this->orderService = $orderService;
        $this->shipmentService = $shipmentService;
        $this->documentContentsService = $documentContentsService;
    }

    /**
     * @param array $orderIds
     * @param Context $context
     * @return array|null
     * @throws \Throwable
     */
    public function generate(array $orderIds, Context $context): ?array
    {
        $this->createShipments($orderIds, $context);
        $documents = $this->getDocuments($orderIds, $context);
        if (empty($documents)) {
            return null;
        }
        $data = [];
        foreach ($documents as $id => $document) {
            if ($document['document'] instanceof DocumentEntity) {
                $data[$document['order_number']] = $this->documentContentsService->readDocumentContents($document['document']);

                try {
                    $this->orderService->orderStateTransition(
                        $document['order_id'],
                        StateMachineTransitionActions::ACTION_COMPLETE,
                        new ParameterBag(),
                        $context
                    );
                } catch (\Exception $exception) {
                }

                try {
                    $this->orderService->orderDeliveryStateTransition(
                        $document['order_delivery_id'],
                        StateMachineTransitionActions::ACTION_SHIP,
                        new ParameterBag(),
                        $context
                    );
                } catch (\Exception $exception) {
                }

                try {
                    $this->orderService->orderTransactionStateTransition(
                        $document['order_transaction_id'],
                        StateMachineTransitionActions::ACTION_COMPLETE,
                        new ParameterBag(),
                        $context
                    );
                } catch (\Exception $exception) {
                }
            }
        }

        return $data;
    }

    /**
     * @param array $orderIds
     * @param Context $context
     * @throws \Throwable
     */
    protected function createShipments(array $orderIds, Context $context): void
    {
        $carrier = $this->getDefaultCarrier($context);

        $orderIds = $this->getOrdersWithoutDocuments($orderIds, $context);

        foreach ($orderIds as $orderId) {
            $shipmentBlueprint = $this->shipmentService->createShipmentBlueprintForOrder($orderId, $context);
            if (empty($shipmentBlueprint->getCarrierTechnicalName())) {
                $shipmentBlueprint->setCarrierTechnicalName($carrier->getTechnicalName());
                $shipmentBlueprint->setShipmentConfig($carrier->getShipmentConfigDefaultValues());
            }
            try {
                $this->shipmentService->createShipmentForOrder($shipmentBlueprint, $orderId, $context);
            } catch (\Exception $exception) {

            }
        }
    }

    /**
     * @param array $orderIds
     * @param Context $context
     * @return mixed
     */
    protected function getDocuments(array $orderIds, Context $context): array
    {
        $query = new QueryBuilder($this->connection);
        $expr = $query->expr();
        $query->select('d.id', 'o.id AS order_id', 'o.order_number', 'od.id AS order_delivery_id', 'ot.id AS order_transaction_id')
            ->from('pickware_dhl_shipment_order_mapping', 'som')
            ->innerJoin('som', 'pickware_dhl_document_shipment_mapping', 'dsm', 'som.shipment_id=dsm.shipment_id')
            ->innerJoin('dsm', 'pickware_document', 'd', 'dsm.document_id=d.id')
            ->innerJoin('som', '`order`', 'o', 'som.order_id=o.id')
            ->leftJoin('o', 'order_delivery', 'od', 'od.order_id=o.id')
            ->leftJoin('o', 'order_transaction', 'ot', 'od.order_id=o.id')
            ->where($expr->in('som.order_id', ':orderIds'))
            ->andWhere($expr->eq('o.version_id', ':versionId'))
            ->andWhere($expr->eq('d.document_type_technical_name', ':docTypeTechnicalName'))
            ->setParameter('orderIds', Uuid::fromHexToBytesList($orderIds), Connection::PARAM_STR_ARRAY)
            ->setParameter('docTypeTechnicalName', self::DOCUMENT_TYPE_TECHNICAL_NAME_SHIPPING_LABEL)
            ->setParameter('versionId', Uuid::fromHexToBytes($context->getVersionId()))
            ->groupBy('som.order_id')
            ->orderBy('d.created_at', 'DESC')
            ->addOrderBy('od.created_at', 'DESC')
            ->addOrderBy('ot.created_at', 'DESC');
        $result = $query->execute()->fetchAll();
        if (empty($result)) {
            return [];
        }
        $result = array_map(function ($data) {
            $data['id'] = Uuid::fromBytesToHex($data['id']);
            $data['order_id'] = Uuid::fromBytesToHex($data['order_id']);
            $data['order_delivery_id'] = Uuid::fromBytesToHex($data['order_delivery_id']);
            $data['order_transaction_id'] = Uuid::fromBytesToHex($data['order_transaction_id']);
            return $data;
        }, $result);
        $result = array_combine(array_column($result, 'id'), $result);
        $documents = $this->pickwareDocumentRepository->search(new Criteria(array_keys($result)), $context)->getElements();

        foreach ($documents as $document) {
            if ($document instanceof DocumentEntity) {
                if (isset($result[$document->getId()])) {
                    $result[$document->getId()]['document'] = $document;
                }
            }
        }

        return $result;
    }

    /**
     * @param array $orderIds
     * @param Context $context
     * @return array
     */
    protected function getOrdersWithoutDocuments(array $orderIds, Context $context): array
    {
        $query = new QueryBuilder($this->connection);
        $expr = $query->expr();
        $query->select('som.order_id')
            ->from('pickware_dhl_shipment_order_mapping', 'som')
            ->innerJoin('som', 'pickware_dhl_document_shipment_mapping', 'dsm', 'som.shipment_id=dsm.shipment_id')
            ->innerJoin('dsm', 'pickware_document', 'd', 'dsm.document_id=d.id')
            ->where($expr->in('som.order_id', ':orderIds'))
            ->andWhere($expr->eq('d.document_type_technical_name', ':docTypeTechnicalName'))
            ->setParameter('orderIds', Uuid::fromHexToBytesList($orderIds), Connection::PARAM_STR_ARRAY)
            ->setParameter('docTypeTechnicalName', self::DOCUMENT_TYPE_TECHNICAL_NAME_SHIPPING_LABEL)
            ->groupBy('som.order_id');
        $ids = Uuid::fromBytesToHexList(array_column($query->execute()->fetchAll(), 'order_id'));
        return array_diff($orderIds, $ids);
    }

    /**
     * @param Context $context
     * @return CarrierEntity
     */
    private function getDefaultCarrier(Context $context): CarrierEntity
    {
        $criteria = (new Criteria())
            ->addFilter(new Filter\EqualsFilter('technicalName', 'dhl'));
        return $this->pickwareDhlCarrierRepository->search($criteria, $context)->first();
    }
}