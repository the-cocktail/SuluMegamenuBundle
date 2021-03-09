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

namespace TheCocktail\Bundle\MegaMenuBundle\Builder;

use Sulu\Component\DocumentManager\Exception\DocumentNotFoundException;
use Sulu\Component\Webspace\Analyzer\RequestAnalyzerInterface;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use TheCocktail\Bundle\MegaMenuBundle\Entity\MenuItem;
use TheCocktail\Bundle\MegaMenuBundle\Exception\NotPublishedException;
use TheCocktail\Bundle\MegaMenuBundle\Repository\MenuItemRepository;
use FOS\HttpCacheBundle\Http\SymfonyResponseTagger;
use Sulu\Component\Content\Compat\Structure\StructureBridge;
use Sulu\Component\Content\Mapper\ContentMapperInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * @author Pablo Lozano <lozanomunarriz@gmail.com>
 */
class MenuBuilder
{
    const MENU_ALL = 'sulu-megamenu-all';

    private MenuItemRepository $repository;
    private ContentMapperInterface $contentMapper;
    private TagAwareCacheInterface $cache;
    private WebspaceManagerInterface $webspaceManager;
    private RequestAnalyzerInterface $requestAnalyzer;
    private ?SymfonyResponseTagger $responseTagger;

    /**
     * @var string[] $tags
     */
    private array $tags;
    private string $environment;

    public function __construct(
        MenuItemRepository $repository,
        ContentMapperInterface $contentMapper,
        TagAwareCacheInterface $cache,
        WebspaceManagerInterface $webspaceManager,
        RequestAnalyzerInterface $requestAnalyzer,
        string $environment,
        ?SymfonyResponseTagger $responseTagger
    ) {
        $this->repository = $repository;
        $this->contentMapper = $contentMapper;
        $this->cache = $cache;
        $this->webspaceManager = $webspaceManager;
        $this->requestAnalyzer = $requestAnalyzer;
        $this->environment = $environment;
        $this->responseTagger = $responseTagger;
    }

    public function build(string $webspace, string $resourceKey, string $locale): array
    {
        $this->tags = [self::MENU_ALL];

        $key = 'menu-' . $webspace . $resourceKey . $locale;
        $headersKey = 'headers-' . $webspace . $resourceKey . $locale;

        $menu = $this->cache->get($key, function (ItemInterface $item) use ($webspace, $resourceKey, $locale): array {
            $menuItems = $this->repository->findBy([
                'webspace' => $webspace,
                'resourceKey' => $resourceKey,
                'locale' => $locale,
                'parent' => null
            ], ['position' => 'ASC'], PHP_INT_MAX);

            $list = $this->recursiveList($menuItems);
            $item->tag($this->tags);

            return $list;
        });

        $headersTags = $this->cache->get($headersKey, function (ItemInterface $item) {
            $item->tag($this->tags);
            return $this->tags;
        });

        if ($this->responseTagger) {
            $this->responseTagger->addTags($headersTags);
        }

        return $menu;
    }

    /**
     * @param MenuItem[] $menuItems
     * @return array
     */
    private function recursiveList(array $menuItems): array
    {
        usort($menuItems, function ($a, $b) {
            return ($a->getPosition() === $b->getPosition()) ? 0 : (($a->getPosition() < $b->getPosition()) ? -1: 1);
        });
        $data = [];
        foreach ($menuItems as $menuItem) {
            try {
                $url = $this->resolveUrl($menuItem);
            } catch (NotPublishedException|DocumentNotFoundException $exception) {
                continue;
            }
            $media = $menuItem->getMedia();
            $item = [
                'id' => $menuItem->getId(),
                'title' => $menuItem->getTitle(),
                'url' => $url,
                'media' => $media ? $media->getId() : null,
                'hasChildren' => $menuItem->hasChildren(),
                'resourceKey' => $menuItem->getResourceKey()
            ];
            if ($menuItem->getChildren()->count()) {
                $item['children'] = $this->recursiveList($menuItem->getChildren()->toArray());
            }
            $data[] = $item;
        }
        return $data;
    }

    private function resolveUrl(MenuItem $item): ?string
    {
        if (!$uuid = $item->getUuid()) {
            return $item->getLink() ?? null;
        }
        $structure = $this->contentMapper->load($uuid, $item->getResourceKey(), $item->getLocale());
        if (!$structure->getPublishedState()) {
            throw new NotPublishedException();
        }
        if (!$structure instanceof StructureBridge) {
            return null;
        }
        $this->tags[] = $structure->getUuid();

        // Build URL
        $scheme = $this->requestAnalyzer->getAttribute('scheme');
        $host = $this->requestAnalyzer->getAttribute('host');
        $locale = $item->getLocale();
        $webspaceKey = $item->getWebspace();

        $url = $this->webspaceManager->findUrlByResourceLocator(
            $structure->getResourceLocator(),
            $this->environment,
            $locale,
            $webspaceKey,
            $host,
            $scheme
        );

        return $url ?: $structure->getResourceLocator();
    }
}
