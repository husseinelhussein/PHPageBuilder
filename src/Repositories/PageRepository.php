<?php

namespace PHPageBuilder\Repositories;

use PHPageBuilder\Page;
use PHPageBuilder\Contracts\PageContract;
use PHPageBuilder\Contracts\PageRepositoryContract;
use Exception;

class PageRepository extends BaseRepository implements PageRepositoryContract
{
    /**
     * The pages database table.
     *
     * @var string
     */
    protected $table;

    /**
     * The class that represents each page.
     *
     * @var string
     */
    protected $class;

    /**
     * PageRepository constructor.
     */
    public function __construct()
    {
        $this->table = empty(phpb_config('page.table')) ? 'pages' : phpb_config('page.table');
        parent::__construct();
        $this->class = phpb_instance('page');
    }

    /**
     * Create a new page.
     *
     * @param array $data
     * @return bool|object|null
     * @throws Exception
     */
    public function create(array $data)
    {
        foreach (['name', 'layout'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field])) {
                return false;
            }
        }
        $builderData = isset($data['data'])? $data['data']: null;
        $page = parent::create([
            'name' => $data['name'],
            'layout' => $data['layout'],
            'data' => $builderData,
        ]);
        if (! ($page instanceof PageContract)) {
            throw new Exception("Page not of type PageContract");
        }
        return $this->replaceTranslations($page, $data);
    }

    /**
     * Update the given page with the given updated data.
     *
     * @param $page
     * @param array $data
     * @return bool|object|null
     */
    public function update($page, array $data)
    {
        foreach (['name', 'layout'] as $field) {
            if (! isset($data[$field]) || ! is_string($data[$field])) {
                return false;
            }
        }

        $page->invalidateCache();
        $this->replaceTranslations($page, $data);

        return parent::update($page, [
            'name' => $data['name'],
            'layout' => $data['layout'],
        ]);
    }

    /**
     * Replace the translations of the given page by the given data.
     *
     * @param PageContract $page
     * @param array $data
     * @return bool
     */
    protected function replaceTranslations(PageContract $page, array $data)
    {
        $activeLanguages = phpb_active_languages();
        foreach (['title', 'route'] as $field) {
            foreach ($activeLanguages as $languageCode => $languageTranslation) {
                if (! isset($data[$field][$languageCode])) {
                    return false;
                }
            }
        }

        $pageTranslationRepository = new PageTranslationRepository;
        $pageTranslationRepository->destroyWhere(phpb_config('page.translation.foreign_key'), $page->getId());
        foreach ($activeLanguages as $languageCode => $languageTranslation) {
            $pageTranslationRepository->create([
                phpb_config('page.translation.foreign_key') => $page->getId(),
                'locale' => $languageCode,
                'title' => $data['title'][$languageCode],
                'route' => $data['route'][$languageCode],
            ]);
        }

        return true;
    }

    /**
     * Update the given page with the given updated page data
     *
     * @param $page
     * @param array $data
     * @return bool|object|null
     */
    public function updatePageData($page, array $data)
    {
        $page->invalidateCache();

        return parent::update($page, [
            'data' => json_encode($data),
        ]);
    }

    /**
     * Remove the given page from the database.
     *
     * @param $id
     * @return bool
     */
    public function destroy($id)
    {
        $this->findWithId($id)->invalidateCache();

        return parent::destroy($id);
    }

    public function duplicate(Page $page){
        $translations = $page->getTranslations();
        $buildrData = $page->getBuilderData();
        $pb_data = [
            'layout' => $page->getLayout(),
            'title' => [],
            'route' => [],
            'data' => json_encode($buildrData),
        ];
        foreach ($translations as $langCode => $translation) {
            foreach (array_keys($translation) as $prop) {
                if(is_int($prop)){
                    unset($translation[$prop]);
                }
            }
            $newTitle = $this->generateUniqueTitle($translation['title']);
            if(!isset($pb_data['name'])){
                $pb_data['name'] = $newTitle;
            }
            $pb_data['title'][$langCode] = $newTitle;
            $pb_data['route'][$langCode] = $this->generateUniqueRoute($translation['route']);
        }
        $pb_page = $this->create($pb_data);
        $m = 'Something went wrong, failed to create page';
        if(!$pb_page){
            throw new Exception($m);
        }
        return true;
    }

    protected function generateUniqueRoute($existingRoute) {
        $route = $this->generateUniqueColVal('route', $existingRoute);
        return $route;
    }

    protected function generateUniqueTitle($existingTitle) {
        $name = $this->generateUniqueColVal('title', $existingTitle, true);
        return $name;
    }

    protected function generateUniqueColVal($col, $exitingVal, $space = false) {
        $pb_page_tr_repo = new PageTranslationRepository;
        $number = 2;
        do {
            // remove any existing numbers
            $exitingVal = preg_replace(['/(\s\-\s)\d+/','/(\-)\d+/'], '', $exitingVal);
            $exitingVal = preg_replace('/\-\d+/', '', $exitingVal);
            if ($space) {
                $newVal = preg_replace('/\s\d+/', '', $exitingVal);
                $newVal .= ' - ' . $number;
            }
            else {
                $newVal = $exitingVal;
                $newVal .= '-' . $number;
            }
            $pb_page = $pb_page_tr_repo->findWhere($col, $newVal);
            $number++;
        }
        while ( !empty($pb_page) );
        return $newVal;
    }
}
