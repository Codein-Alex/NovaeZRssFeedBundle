<?php
/**
 * NovaeZRssFeedBundle.
 *
 * @package   NovaeZRssFeedBundle
 *
 * @author    Novactive
 * @copyright 2018 Novactive
 * @license   https://github.com/Novactive/NovaeZRssFeedBundle/blob/master/LICENSE
 */

namespace Novactive\EzRssFeedBundle\Controller\Admin;

use Doctrine\Common\Collections\ArrayCollection;
use eZ\Bundle\EzPublishCoreBundle\Controller;
use eZ\Publish\API\Repository\PermissionResolver;
use eZ\Publish\API\Repository\Values\Content\Location;
use eZ\Publish\Core\Base\Exceptions\UnauthorizedException;
use eZ\Publish\Core\Repository\Values\ContentType\ContentType;
use EzSystems\EzPlatformAdminUi\Notification\NotificationHandlerInterface;
use Novactive\EzRssFeedBundle\Entity\RssFeeds;
use Novactive\EzRssFeedBundle\Form\RssFeedsType;
use Pagerfanta\Adapter\ArrayAdapter;
use Pagerfanta\Pagerfanta;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @Route("/rssfeeds")
 *
 * Class RssFeedController
 *
 * @package Novactive\EzRssFeedBundle\Controller
 */
class RssFeedController extends Controller
{
    private $defaultPaginationLimit = 10;

    private $notificationHandler;

    public function __construct(NotificationHandlerInterface $notificationHandler)
    {
        $this->notificationHandler = $notificationHandler;
    }

    /**
     * @Route("/", name="platform_admin_ui_rss_feeds_list")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function listAction(Request $request): Response
    {
        $rssFeedRepository = $this->getDoctrine()->getRepository(RssFeeds::class);

        /**
         * @var PermissionResolver
         */
        $permissionResolver = $this->container->get('ezpublish.api.repository')->getPermissionResolver();

        $page = $request->query->get('page') ?? 1;

        $pagerfanta = new Pagerfanta(
            new ArrayAdapter($rssFeedRepository->findAll())
        );

        $pagerfanta->setMaxPerPage($this->defaultPaginationLimit);
        $pagerfanta->setCurrentPage(min($page, $pagerfanta->getNbPages()));

        return $this->render(
            '@ezdesign/rssfeed/list.html.twig',
            [
                'pager'     => $pagerfanta,
                'canCreate' => $permissionResolver->hasAccess('rss', 'create'),
                'canDelete' => $permissionResolver->hasAccess('rss', 'delete'),
            ]
        );
    }

    /**
     * @Route("/add", name="platform_admin_ui_rss_feeds_create")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function createAction(Request $request)
    {
        /**
         * @var PermissionResolver
         */
        $permissionResolver = $this->getRepository()->getPermissionResolver();

        if (!$permissionResolver->hasAccess('rss', 'create')) {
            throw new UnauthorizedException(
                'rss',
                'create',
                []
            );
        }

        $rssFeed = new RssFeeds();
        $form    = $this->createForm(RssFeedsType::class, $rssFeed);

        $form->add(
            'submit',
            SubmitType::class,
            [
                'label' => 'Create',
                'attr'  => [
                    'class' => 'btn btn-default pull-right',
                    'id'    => 'rss_edit_edit',
                ],
            ]
        );

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $rssFeed->setStatus(RssFeeds::STATUS_ENABLED);
            $entityManager->persist($rssFeed);
            $entityManager->flush();

            return $this->redirectToRoute('platform_admin_ui_rss_feeds_list');
        }

        return $this->render(
            '@ezdesign/rssfeed/edit.html.twig',
            [
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @Route("/edit/{id}", name="platform_admin_ui_rss_feeds_edit")
     * @ParamConverter("rssFeed", class="EzRssFeedBundle:RssFeeds")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function editAction(Request $request, RssFeeds $rssFeed)
    {
        /**
         * @var PermissionResolver
         */
        $permissionResolver = $this->getRepository()->getPermissionResolver();

        if (!$permissionResolver->hasAccess('rss', 'edit')) {
            throw new UnauthorizedException(
                'rss',
                'edit',
                []
            );
        }

        $em                 = $this->getDoctrine()->getManager();
        $originalFeedsItems = new ArrayCollection();
        foreach ($rssFeed->getFeedItems() as $item) {
            $originalFeedsItems->add($item);
        }
        $feedForm = $this->createForm(RssFeedsType::class, $rssFeed);
        $feedForm->add(
            'submit',
            SubmitType::class,
            [
                'label' => 'Create',
                'attr'  => [
                    'class' => 'btn-secondary btn',
                ],
            ]
        );
        $feedForm->handleRequest($request);

        if ($feedForm->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();

            $entityManager->persist($rssFeed);

            foreach ($originalFeedsItems as $originalChild) {
                if (false === $rssFeed->getFeedItems()->contains($originalChild)) {
                    $rssFeed->removeFeedItem($originalChild);
                    $originalChild->setRssFeeds(null);
                    $em->remove($originalChild);
                }
            }

            $entityManager->flush();

            $this->getNotificationHandler()->success('Mise à jour effectuée avec succès.');

            return new RedirectResponse($this->generateUrl('platform_admin_ui_rss_feeds_list'));
        }

        return $this->render(
            '@ezdesign/rssfeed/edit.html.twig',
            [
                'form' => $feedForm->createView(),
            ]
        );
    }

    /**
     * @return NotificationHandlerInterface
     */
    public function getNotificationHandler(): NotificationHandlerInterface
    {
        return $this->notificationHandler;
    }

    /**
     * @Route("/delete/{id}", name="platform_admin_ui_rss_feeds_delete")
     * @ParamConverter("rssFeed", class="EzRssFeedBundle:RssFeeds")
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction(Request $request, RssFeeds $rssFeed)
    {
        /**
         * @var PermissionResolver
         */
        $permissionResolver = $this->getRepository()->getPermissionResolver();

        if (!$permissionResolver->hasAccess('rss', 'delete')) {
            throw new UnauthorizedException(
                'rss',
                'delete',
                []
            );
        }

        $em = $this->getDoctrine()->getManager();
        if ($request->request) {
            $em->remove($rssFeed);
            $em->flush();
        }

        return new RedirectResponse($this->generateUrl('platform_admin_ui_rss_feeds_list'));
    }

    /**
     * @Route("/rss_feed/ajx/location/{locationId}", name="platform_admin_ui_rss_feeds_ajx_load_location")
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function loadLocationAjaxAction(Request $request, $locationId = null)
    {
        /**
         * @var PermissionResolver
         */
        $permissionResolver = $this->getRepository()->getPermissionResolver();

        if (!$permissionResolver->hasAccess('rss', 'edit')) {
            throw new UnauthorizedException(
                'rss',
                'edit',
                []
            );
        }

        $data = [];

        if ($request->get('locationId')) {
            $locationId = $request->get('locationId');
        }

        if ($locationId) {
            $repository      = $this->getRepository();
            $locationService = $repository->getLocationService();
            $location        = $locationService->loadLocation($locationId);

            $data = [
                'location' => $locationId,
                'content'  => [
                    'id'   => $location->contentInfo->id,
                    'name' => $location->contentInfo->name,
                ],
            ];
        }

        return new JsonResponse($data);
    }

    /**
     * @Route("/edit/ajax/get_rss_field_by_contenttype_id", name="platform_admin_ui_rss_ajax_get_fields_by_contenttype_id")
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function getAjaxFieldByContentTypeIdAction(Request $request)
    {
        /**
         * @var PermissionResolver
         */
        $permissionResolver = $this->getRepository()->getPermissionResolver();

        if (!$permissionResolver->hasAccess('rss', 'edit')) {
            throw new UnauthorizedException(
                'rss',
                'edit',
                []
            );
        }

        $fieldsMap = [];

        if ($request->get('contenttype_id')) {
            $contentType = $this->getRepository()
                                ->getContentTypeService()
                                ->loadContentType($request->get('contenttype_id'));

            foreach ($contentType->getFieldDefinitions() as $fieldDefinition) {
                $fieldsMap[ucfirst($fieldDefinition->getName())] =
                    $fieldDefinition->identifier;
            }

            ksort($fieldsMap);
        }

        return new JsonResponse($fieldsMap);
    }

    /**
     * @Route("/ajax/change_visibility_feed", name="platform_admin_ui_rss_ajax_change_visibility_feed")
     * @Method({"POST"})
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function changeAjaxVisibilityFeed(Request $request)
    {
        /**
         * @var PermissionResolver
         */
        $permissionResolver = $this->getRepository()->getPermissionResolver();

        if (!$permissionResolver->hasAccess('rss', 'edit')) {
            throw new UnauthorizedException(
                'rss',
                'edit',
                []
            );
        }

        $repository = $this->getDoctrine()->getRepository(RssFeeds::class);

        /**
         * @var RssFeeds
         */
        $rssFeed = $repository->find($request->get('feedId'));

        if (!empty($rssFeed)) {
            $entityManager = $this->getDoctrine()->getManager();
            $status        = RssFeeds::STATUS_ENABLED ==
                      $rssFeed->getStatus() ? RssFeeds::STATUS_DISABLED : RssFeeds::STATUS_ENABLED;

            $rssFeed->setStatus($status);
            $entityManager->persist($rssFeed);
            $entityManager->flush();

            return new JsonResponse(['success' => true]);
        }

        return new JsonResponse(['success' => false], 404);
    }
}
