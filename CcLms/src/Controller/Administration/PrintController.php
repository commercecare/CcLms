<?php declare(strict_types=1);

namespace CcLms\Controller\Administration;

use Symfony\Component\Routing\Annotation\Route;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Symfony\Component\HttpFoundation\Request;
use CcLms\Service\PrintServiceInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Shopware\Core\Framework\Context;

/**
 * @RouteScope(scopes={"api"})
 */
class PrintController extends AbstractController
{
    /**
     * @var PrintServiceInterface
     */
    private $printService;

    /**
     * CanCustomerController constructor.
     * @param PrintServiceInterface $printService
     */
    public function __construct(
        PrintServiceInterface $printService
    )
    {
        $this->printService = $printService;
    }

    /**
     * @Route("/api/v{version}/_action/cc_lms/print", name="api.action.cc_lms.print", defaults={"auth_required"=true}, methods={"GET"})
     */
    public function print(Request $request, Context $context): Response
    {
        $action = $request->get('action');
        $ids = $request->get('ids');
        $content = $this->printService->print($action, $ids, $context);
        $response = new Response($content);
        $response->headers->set('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');
        $response->headers->set('Content-Type', 'application/zip');
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            $action . '.zip'
        ));
        return $response;
    }
}