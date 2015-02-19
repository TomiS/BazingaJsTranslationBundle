<?php

namespace Bazinga\Bundle\JsTranslationBundle\Controller;

use Bazinga\Bundle\JsTranslationBundle\Finder\TranslationFinder;
use Symfony\Component\Translation\TranslatorInterface;
use Symfony\Component\Templating\EngineInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @author William DURAND <william.durand1@gmail.com>
 */
class Controller
{
    /**
     * @var TranslatorInterface
     */
    protected $translator;

    /**
     * @var EngineInterface
     */
    protected $engine;

    /**
     * @var TranslationFinder
     */
    protected $translationFinder;

    /**
     * @var array
     */
    protected $loaders = array();

    /**
     * @var string
     */
    protected $cacheDir;

    /**
     * @var boolean
     */
    protected $debug;

    /**
     * @var string
     */
    protected $localeFallback;

    /**
     * @var string
     */
    protected $defaultDomain;

    /**
     * @param TranslatorInterface $translator        The translator.
     * @param EngineInterface     $engine            The engine.
     * @param TranslationFinder   $translationFinder The translation finder.
     * @param string              $cacheDir
     * @param boolean             $debug
     * @param string              $localeFallback
     * @param string              $defaultDomain
     */
    public function __construct(
        TranslatorInterface $translator,
        EngineInterface $engine,
        TranslationFinder $translationFinder,
        $cacheDir,
        $debug          = false,
        $localeFallback = '',
        $defaultDomain  = ''
    ) {
        $this->translator        = $translator;
        $this->engine            = $engine;
        $this->translationFinder = $translationFinder;
        $this->cacheDir          = $cacheDir;
        $this->debug             = $debug;
        $this->localeFallback    = $localeFallback;
        $this->defaultDomain     = $defaultDomain;
    }

    /**
     * Add a translation loader if it does not exist.
     *
     * @param string          $id     The loader id.
     * @param LoaderInterface $loader A translation loader.
     */
    public function addLoader($id, $loader)
    {
        if (!array_key_exists($id, $this->loaders)) {
            $this->loaders[$id] = $loader;
        }
    }

    public function getTranslationsAction(Request $request, $domain, $_format)
    {
        $locales = $this->getLocales($request);
        $cache   = new ConfigCache(sprintf('%s/%s.%s.%s',
            $this->cacheDir,
            $domain,
            implode('-', $locales),
            $_format
        ), $this->debug);

        if (!$cache->isFresh()) {
            $resources    = array();
            $translations = array();

            foreach ($locales as $locale) {
                $translations[$locale] = array();

                $files = $this->translationFinder->get($domain, $locale);

                if (1 > count($files)) {
                    continue;
                }

                $translations[$locale][$domain] = array();

                foreach ($files as $file) {
                    $extension = pathinfo($file->getFilename(), \PATHINFO_EXTENSION);

                    if (isset($this->loaders[$extension])) {
                        $resources[] = new FileResource($file->getPath());
                        $catalogue   = $this->loaders[$extension]
                            ->load($file, $locale, $domain);

                        $translations[$locale][$domain] = array_replace_recursive(
                            $translations[$locale][$domain],
                            $catalogue->all($domain)
                        );
                    }
                }
            }

            $content = $this->engine->render('BazingaJsTranslationBundle::getTranslations.' . $_format . '.twig', array(
                'fallback'       => $this->localeFallback,
                'defaultDomain'  => $this->defaultDomain,
                'translations'   => $translations,
                'include_config' => true,
            ));

            try {
                $cache->write($content, $resources);
            } catch (IOException $e) {
                throw new NotFoundHttpException();
            }
        }

        $response = new Response(
            file_get_contents((string) $cache),
            200,
            array('Content-Type' => $request->getMimeType($_format))
        );
        $response->prepare($request);
        $response->setPublic();
        $response->setETag(md5($response->getContent()));
        $response->isNotModified($request);

        return $response;
    }

    protected function getLocales(Request $request)
    {
        if (null !== $locales = $request->query->get('locales')) {
            $locales = explode(',', $locales);
        } else {
            $locales = array($request->getLocale());
        }

        $locales = array_filter($locales, function ($locale) {
            return strcasecmp(\Locale::getDisplayLanguage($locale), $locale) !== 0;
        });

        return array_unique(array_map(function ($locale) {
            return trim($locale);
        }, $locales));
    }
}
