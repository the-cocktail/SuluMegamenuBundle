<?php

/**
 * This file is part of Sulu Megamenu Bundle.
 *
 * (c) The Cocktail Experience S.L.
 *
 *  This source file is subject to the MIT license that is bundled
 *  with this source code in the file LICENSE.
 */

declare(strict_types=1);

namespace TheCocktail\Bundle\MegaMenuBundle\Controller\Admin;

use Sulu\Bundle\MediaBundle\Media\Manager\MediaManagerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use TheCocktail\Bundle\MegaMenuBundle\Entity\MenuItem;
use TheCocktail\Bundle\MegaMenuBundle\Event\MenuEvent;
use TheCocktail\Bundle\MegaMenuBundle\Exception\WebspaceNotFoundException;
use TheCocktail\Bundle\MegaMenuBundle\Repository\MenuItemRepository;
use FOS\RestBundle\Routing\ClassResourceInterface;
use FOS\RestBundle\View\ViewHandlerInterface;
use Sulu\Component\Rest\AbstractRestController;
use Sulu\Component\Rest\ListBuilder\Doctrine\DoctrineListBuilderFactoryInterface;
use Sulu\Component\Rest\ListBuilder\Doctrine\FieldDescriptor\DoctrineFieldDescriptor;
use Sulu\Component\Rest\ListBuilder\ListRepresentation;
use Sulu\Component\Rest\RestHelperInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * @author Pablo Lozano <lozanomunarriz@gmail.com>
 */
class MegamenuController extends AbstractRestController implements ClassResourceInterface
{
    private MenuItemRepository $repository;
    private SluggerInterface $slugger;
    private RestHelperInterface $restHelper;
    private DoctrineListBuilderFactoryInterface $listBuilderFactory;
    private WebspaceManagerInterface $webspaceManager;
    private EventDispatcherInterface $eventDispatcher;
    private MediaManagerInterface $mediaManager;
    private array $megamenus;

    private $fieldDescriptors;

    public function __construct(
        MenuItemRepository $repository,
        ViewHandlerInterface $viewHandler,
        SluggerInterface $slugger,
        RestHelperInterface $restHelper,
        DoctrineListBuilderFactoryInterface $listBuilderFactory,
        WebspaceManagerInterface $webspaceManager,
        EventDispatcherInterface $eventDispatcher,
        MediaManagerInterface $mediaManager,
        array $megamenus
    ) {
        parent::__construct($viewHandler);
        $this->repository = $repository;
        $this->slugger = $slugger;
        $this->restHelper = $restHelper;
        $this->listBuilderFactory = $listBuilderFactory;
        $this->webspaceManager = $webspaceManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->mediaManager = $mediaManager;
        $this->megamenus = $megamenus;

        $this->fieldDescriptors = [];
        $this->fieldDescriptors['id'] = new DoctrineFieldDescriptor('id', 'id', MenuItem::class);
        $this->fieldDescriptors['title'] = new DoctrineFieldDescriptor('title', 'title', MenuItem::class);
        $this->fieldDescriptors['created'] = new DoctrineFieldDescriptor('created', 'created', MenuItem::class);
        $this->fieldDescriptors['changed'] = new DoctrineFieldDescriptor('changed', 'changed', MenuItem::class);
    }

    public function cgetAction(Request $request): Response
    {
        $locale = $request->query->get('locale');
        if (!$webspaceKey = $request->query->get('webspace')) {
            throw new WebspaceNotFoundException('Webspace key not found in request');
        }

        if (!$webspace = $this->webspaceManager->getWebspaceCollection()->getWebspace($webspaceKey)) {
            throw new WebspaceNotFoundException(sprintf('Webspace %s not found', $webspaceKey));
        }

        if ($parent = $request->query->get('parentId')) {
            $attr = ['webspace' => $webspaceKey, 'locale' => $locale, 'parent' => $parent];

            if (!is_numeric($parent)) {
                $attr['resourceKey'] = $parent;
                $attr['parent'] = null;
            }

            $items = $this->repository->findBy($attr, ['position' => 'ASC'], PHP_INT_MAX);

            $data = $this->recursiveList($items);
        } else {
            $data = [];

            foreach ($this->megamenus as $resourceKey => $megamenu) {
                $expanded = $request->query->get('expandedIds');
                $children = null;
                $count = $this->repository->count([
                    'webspace' => $webspaceKey,
                    'locale' => $locale,
                    'resourceKey' => $resourceKey
                ]);
                if ($expanded === $resourceKey) {
                    $children = $this->repository->findBy([
                        'webspace' => $webspaceKey,
                        'locale' => $locale,
                        'resourceKey' => $resourceKey,
                        'parent' => null,
                    ], ['position' => 'ASC'], PHP_INT_MAX);
                }
                $data[] = [
                    'id' => $resourceKey,
                    'title' => $megamenu['title'],
                    'position' => 'Base',
                    'parent' => false,
                    'webspace' => $webspaceKey,
                    'hasChildren' => $count ? true: false,
                    "_permissions" => [
                        "view" => false,
                        "add" => true,
                        "edit" => false,
                        "delete" => false
                    ],
                    '_embedded' => ['navigation' => $children ? $this->recursiveList($children): []]
                ];
            }
        }

        $listBuilder = $this->listBuilderFactory->create(MenuItem::class);
        $this->restHelper->initializeListBuilder($listBuilder, $this->fieldDescriptors);

        $listRepresentation = new ListRepresentation(
            $data,
            MenuItem::RESOURCE_KEY,
            'sulu_megamenus.get_megamenus',
            $request->request->all(),
            $listBuilder->getCurrentPage(),
            $listBuilder->getLimit(),
            $listBuilder->count()
        );

        return $this->handleView($this->view($listRepresentation));
    }

    public function getAction(int $id, Request $request): Response
    {
        $entity = $this->load($id);
        if (!$entity) {
            throw new NotFoundHttpException();
        }

        return $this->handleView($this->view($entity));
    }

    public function postAction(Request $request): Response
    {
        $entity = new MenuItem();
        //$entity->setEnabled(true);

        $this->mapDataToEntity($request, $entity);

        $this->save($entity);

        return $this->handleView($this->view($entity));
    }

    public function putAction(int $id, Request $request): Response
    {
        $entity = $this->load($id);
        if (!$entity) {
            throw new NotFoundHttpException();
        }

        $this->mapDataToEntity($request, $entity);

        $this->save($entity);

        return $this->handleView($this->view($entity));
    }

    public function deleteAction(int $id): Response
    {
        $this->remove($id);

        return $this->handleView($this->view());
    }

    protected function mapDataToEntity(Request $request, MenuItem $entity): MenuItem
    {
        $data = $request->request->all();
        $data['parent'] = $request->query->get('parentId');

        if ($data['parent'] && !is_numeric($data['parent'])) {
            $data['resourceKey'] = $data['parent'];
            $data['parent'] = null;
        } elseif ($data['parent'] && $parent = $this->repository->find($data['parent'])) {
            $data['resourceKey'] = $parent->getResourceKey();
        }

        $data['locale'] = $request->query->get('locale');
        if (!$data['webspace'] = $request->query->get('webspace')) {
            throw new WebspaceNotFoundException();
        }

        $entity->setTitle($data['title']);
        $entity->setLocale($data['locale']);
        $entity->setPosition((int) $data['position']);

        if ($data['media']) {
            $mediaId = $data['media']['id'];
            if ($mediaId) {
                $media = $this->mediaManager->getById($mediaId, $data['locale']);
                $entity->setMedia($media->getEntity());
            } else {
                $entity->setMedia(null);
            }
        }

        $entity->setResourceKey($data['resourceKey'] ?? $entity->getResourceKey());

        $entity->setWebspace($data['webspace']);

        if ($data['parent']) {
            $parent = $this->repository->find($data['parent']);
            $entity->setParent($parent);
        }

        $entity->setUuid($data['uuid']);
        $entity->setLink($data['link']);
        
        return $entity;
    }

    protected function load(int $id): ?MenuItem
    {
        return $this->repository->find($id);
    }

    protected function save(MenuItem $entity): void
    {
        $this->repository->save($entity);
        $event = new MenuEvent($entity);
        $this->eventDispatcher->dispatch($event, MenuEvent::PUBLISHED);
    }

    protected function remove(int $id): void
    {
        $this->repository->remove($id);
    }

    /**
     * @param MenuItem[] $menuItems
     * @return array
     */
    private function recursiveList(array $menuItems): array
    {
        $data = [];
        foreach ($menuItems as $menuItem) {
            $item = [
                'id' => $menuItem->getId(),
                'title' => $menuItem->getTitle(),
                'position' => $menuItem->getPosition(),
                'parent' => ($parent = $menuItem->getParent()) ? $parent->getId(): null,
                'webspace' => $menuItem->getWebspace(),
                'resourceKey' => $menuItem->getResourceKey(),
                'hasChildren' => $menuItem->hasChildren(),
                "_permissions" => [
                    "view" => true,
                    "add" => true,
                    "edit" => true,
                    "delete" => true
                ]
            ];
            if ($menuItem->getChildren()->count()) {
                $item['_embedded'][MenuItem::RESOURCE_KEY] = $this->recursiveList($menuItem->getChildren()->toArray());
            }
            $data[] = $item;
        }
        return $data;
    }
}
