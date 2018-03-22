<?php

namespace Emmedy\H5PBundle\Core;

use Doctrine\ORM\EntityManager;
use Emmedy\H5PBundle\Editor\EditorStorage;
use Emmedy\H5PBundle\Entity\Content;
use Emmedy\H5PBundle\Entity\ContentLibraries;
use Emmedy\H5PBundle\Entity\LibrariesHubCache;
use Emmedy\H5PBundle\Entity\LibrariesLanguages;
use Emmedy\H5PBundle\Entity\Library;
use Emmedy\H5PBundle\Entity\LibraryLibraries;
use GuzzleHttp\Client;
use H5PPermission;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class H5PSymfony implements \H5PFrameworkInterface {
    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;
    /**
     * @var EntityManager
     */
    private $manager;
    /**
     * @var FlashBagInterface
     */
    private $flashBag;
    /**
     * @var AuthorizationCheckerInterface
     */
    private $authorizationChecker;
    /**
     * @var H5POptions
     */
    private $options;
    /**
     * @var EditorStorage
     */
    private $editorStorage;

    /**
     * H5PSymfony constructor.
     * @param H5POptions $options
     * @param EditorStorage $editorStorage
     * @param TokenStorageInterface $tokenStorage
     * @param EntityManager $manager
     * @param FlashBagInterface $flashBag
     * @param AuthorizationCheckerInterface $authorizationChecker
     */
    public function __construct(H5POptions $options, EditorStorage $editorStorage, TokenStorageInterface $tokenStorage, EntityManager $manager, FlashBagInterface $flashBag, AuthorizationCheckerInterface $authorizationChecker)
    {
        $this->options = $options;
        $this->editorStorage = $editorStorage;
        $this->tokenStorage = $tokenStorage;
        $this->manager = $manager;
        $this->flashBag = $flashBag;
        $this->authorizationChecker = $authorizationChecker;
    }

    /**
   * Grabs the relative URL to H5P files folder.
   *
   * @return string
   */
  public function getRelativeH5PPath() {
    return $this->options->getRelativeH5PPath();
  }

    /**
   * Prepares the generic H5PIntegration settings
   */
  public function getGenericH5PIntegrationSettings() {
    static $settings;

    if (!empty($settings)) {
      return $settings; // Only needs to be generated the first time
    }

    // Load current user
    $user = $this->tokenStorage->getToken()->getUser();

    // Load configuration settings
    $h5p_save_content_state = $this->getOption('save_content_state', FALSE);
    $h5p_save_content_frequency = $this->getOption('save_content_frequency', 30);
    $h5p_hub_is_enabled = $this->getOption('hub_is_enabled', TRUE);

    // Create AJAX URLs
    $set_finished_url = Url::fromUri('internal:/h5p-ajax/set-finished.json', ['query' => ['token' => \H5PCore::createToken('result')]])->toString(TRUE)->getGeneratedUrl();
    $content_user_data_url = Url::fromUri('internal:/h5p-ajax/content-user-data/:contentId/:dataType/:subContentId', ['query' => ['token' => \H5PCore::createToken('contentuserdata')]])->toString(TRUE)->getGeneratedUrl();
    $h5p_url = $GLOBALS['base_path'] . $this->getRelativeH5PPath();

    // Define the generic H5PIntegration settings
    $settings = array(
      'baseUrl' => $GLOBALS['base_path'],
      'url' => $h5p_url,
      'postUserStatistics' => $user->getId() > 0,
      'ajax' => array(
        'setFinished' => $set_finished_url,
        'contentUserData' => str_replace('%3A', ':', $content_user_data_url),
      ),
      'saveFreq' => $h5p_save_content_state ? $h5p_save_content_frequency : FALSE,
      'l10n' => array(
        'H5P' => 'en',
      ),
      'hubIsEnabled' => $h5p_hub_is_enabled,
    );

    if ($user->id()) {
      $settings['user'] = [
        'name' => $user->getUsername(),
        'mail' => $user->getEmail(),
      ];
    }
    else {
      $settings['siteUrl'] = Url::fromUri('internal:/', ['absolute' => TRUE])->toString();
    }

    return $settings;
  }

    /**
   * Get a list with prepared asset links that is used when JS loads components.
   *
   * @param array [$keys] Optional keys, first for JS second for CSS.
   * @return array
   */
  public static function getCoreAssets($keys = NULL) {
    if (empty($keys)) {
      $keys = ['scripts', 'styles'];
    }

    // Prepare arrays
    $assets = [
      $keys[0] => [],
      $keys[1] => [],
    ];

    // Determine cache buster
    $cache_buster = \Drupal::state()->get('system.css_js_query_string', '0');
    $h5p_module_path = drupal_get_path('module', 'h5p');

    // Add all core scripts
    foreach (\H5PCore::$scripts as $script) {
      $assets[$keys[0]][] = "{$h5p_module_path}/vendor/h5p/h5p-core/{$script}?{$cache_buster}";
    }

    // and styles
    foreach (\H5PCore::$styles as $style) {
      $assets[$keys[1]][] = "{$h5p_module_path}/vendor/h5p/h5p-core/{$style}?{$cache_buster}";
    }

    return $assets;
  }

    /**
   *
   */
  public static function aggregatedAssets($scriptAssets, $styleAssets) {
    $jsOptimizer = \Drupal::service('asset.js.collection_optimizer');
    $cssOptimizer = \Drupal::service('asset.css.collection_optimizer');
    $systemPerformance = \Drupal::config('system.performance');
    $jsAssetConfig = ['preprocess' => $systemPerformance->get('js.preprocess')];
    $cssAssetConfig = ['preprocess' => $systemPerformance->get('css.preprocess'), 'media' => 'css'];
    $assets = ['scripts' => [], 'styles' => []];
    foreach ($scriptAssets as $jsFiles) {
      $assets['scripts'][] = self::createCachedPublicFiles($jsFiles, $jsOptimizer, $jsAssetConfig);
    }
    foreach ($styleAssets as $cssFiles) {
      $assets['styles'][] = self::createCachedPublicFiles($cssFiles, $cssOptimizer, $cssAssetConfig);
    }
    return $assets;
  }

    /**
   * Combines a set of files to a cached version, that is public available
   *
   * @param string[] $filePaths
   * @param AssetCollectionOptimizerInterface $optimizer
   * @param array $assetConfig
   *
   * @return string[]
   */
  private static function createCachedPublicFiles(array $filePaths, $optimizer, $assetConfig) {
    $assets = [];

    $defaultAssetConfig = [
      'type' => 'file',
      'group' => 'h5p',
      'cache' => TRUE,
      'attributes' => [],
      'version' => NULL,
      'browsers' => [],
    ];

    foreach ($filePaths as $index => $path) {
      $path = explode('?', $path)[0];

      $assets[$path] = [
        'weight' => count($filePaths) - $index,
        'data' => $path,
      ] + $assetConfig + $defaultAssetConfig;
    }
    $cachedAsset = $optimizer->optimize($assets);

    return array_map(function($publicUrl){ return file_create_url($publicUrl); }, array_column($cachedAsset, 'data'));
  }

    /**
   * Implements getPlatformInfo
   */
  public function getPlatformInfo()
  {
    return [
      'name' => 'drupal',
      'version' => '8.4.2',
      'h5pVersion' => '8.x-1.0-rc5',
    ];
  }

    /**
   * Implements fetchExternalData
   */
  public function fetchExternalData($url, $data = NULL, $blocking = TRUE, $stream = NULL) {

    $options = [];
    if (!empty($data)) {
      $options['headers'] = [
        'Content-Type' => 'application/x-www-form-urlencoded'
      ];
      $options['form_params'] = $data;
    }

    if ($stream) {
      @set_time_limit(0);
    }

    try {
      $client = new Client();
      $response = $client->request(empty($data) ? 'GET' : 'POST', $url, $options);
      $response_data = (string) $response->getBody();
      if (empty($response_data)) {
        return FALSE;
      }

    }
    catch (\Exception $e) {
      $this->setErrorMessage($e->getMessage(), 'failed-fetching-external-data');
      return FALSE;
    }

    if ($stream && empty($response->error)) {
      // Create file from data
      $this->editorStorage->saveFileTemporarily($response_data);
      // TODO: Cannot rely on H5PEditor module – Perhaps we could use the
      // save_to/sink option to save directly to file when streaming ?
      // http://guzzle.readthedocs.io/en/latest/request-options.html#sink-option
      return TRUE;
    }

    return $response_data;
  }

    /**
   * Implements setLibraryTutorialUrl
   *
   * Set the tutorial URL for a library. All versions of the library is set
   *
   * @param string $machineName
   * @param string $tutorialUrl
   */
  public function setLibraryTutorialUrl($machineName, $tutorialUrl) {
      $libraries = $this->manager->getRepository('EmmedyH5PBundle:Library')->findBy(['machineName' => $machineName]);

      foreach ($libraries as $library) {
          $library->setTutorialUrl($tutorialUrl);
          $this->manager->persist($library);
      }
      $this->manager->flush();
  }

    /**
   * Keeps track of messages for the user.
   * @var array
   */
  private $messages = array('error' => array(), 'info' => array());

    /**
   * Implements setErrorMessage
   */
  public function setErrorMessage($message, $code = NULL) {
    $this->flashBag->add("error", "[$code]: $message");
  }

    /**
   * Implements setInfoMessage
   */
  public function setInfoMessage($message) {
      $this->flashBag->add("info", "$message");
  }

    /**
   * Implements getMessages
   */
  public function getMessages($type) {
    if (!$this->flashBag->has($type)) {
      return NULL;
    }
    $messages = $this->flashBag->get($type);
    return $messages;
  }

    /**
   * Implements t
   */
  public function t($message, $replacements = []) {
//    return t($message, $replacements);
      return $message;
  }

    /**
   * Implements getLibraryFileUrl
   */
  public function getLibraryFileUrl($libraryFolderName, $fileName) {
      return $this->options->getLibraryFileUrl($libraryFolderName, $fileName);
  }

    /**
   * Implements getUploadedH5PFolderPath
   */
  public function getUploadedH5pFolderPath($set = NULL) {
      return $this->options->getUploadedH5pFolderPath($set);
  }

    /**
   * Implements getUploadedH5PPath
   */
  public function getUploadedH5pPath($set = NULL) {
    return $this->options->getUploadedH5pPath($set);
  }

    /**
   * Implements loadLibraries
   */
  public function loadLibraries() {
    $res = $this->manager->getRepository('EmmedyH5PBundle:Library')->findBy([], ['title' => 'ASC', 'majorVersion' => 'ASC', 'minorVersion' => 'ASC']);

    $libraries = [];
    foreach ($res as $library) {
      $libraries[$library->getMachineName()][] = $library;
    }

    return $libraries;
  }

    /**
   * Implements getAdminUrl
   */
  public function getAdminUrl() {
    // Misplaced; not used by Core.
    $url = Url::fromUri('internal:/admin/content/h5p')->toString();
    return $url;
  }

    /**
   * Implements getLibraryId
   */
  public function getLibraryId($machineName, $majorVersion = NULL, $minorVersion = NULL) {
      $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->findOneBy(['machineName' => $machineName, 'majorVersion' => $majorVersion, 'minorVersion' => $minorVersion]);

      return $library ? $library->getId() : null;
  }

    /**
   * Implements isPatchedLibrary
   */
  public function isPatchedLibrary($library) {
    if ($this->getOption('dev_mode', FALSE)) {
      return TRUE;
    }

//    $result = db_query(
//        "SELECT 1
//           FROM {h5p_libraries}
//          WHERE machine_name = :machineName
//            AND major_version = :majorVersion
//            AND minor_version = :minorVersion
//            AND patch_version < :patchVersion",
//        [
//          ':machineName' => $library['machineName'],
//          ':majorVersion' => $library['majorVersion'],
//          ':minorVersion' => $library['minorVersion'],
//          ':patchVersion' => $library['patchVersion']
//        ]
//    )->fetchField();
//    return $result === '1';

      return false;
  }

    /**
   * Implements isInDevMode
   */
  public function isInDevMode() {
    $h5p_dev_mode = $this->getOption('dev_mode', FALSE);
    return (bool) $h5p_dev_mode;
  }

    /**
   * Implements mayUpdateLibraries
   */
  public function mayUpdateLibraries()
  {
      return $this->authorizationChecker->isGranted('ROLE_UPDATE_H5P_LIBRARIES');
  }

    /**
   * Implements getLibraryUsage
   *
   * Get number of content using a library, and the number of
   * dependencies to other libraries
   *
   * @param int $libraryId
   * @return array The array contains two elements, keyed by 'content' and 'libraries'.
   *               Each element contains a number
   */
  public function getLibraryUsage($libraryId, $skipContent = FALSE) {
    $usage = [];

    if ($skipContent) {
      $usage['content'] = -1;
    }
    else {
        $usage['content'] = $this->manager->getRepository('EmmedyH5PBundle:Library')->countContentLibrary($libraryId);
    }

    $usage['libraries'] = $this->manager->getRepository('EmmedyH5PBundle:LibraryLibraries')->countLibraries($libraryId);

    return $usage;
  }

    /**
   * Implements getLibraryContentCount
   *
   * Get a key value list of library version and count of content created
   * using that library.
   *
   * @return array
   *  Array containing library, major and minor version - content count
   *  e.g. "H5P.CoursePresentation 1.6" => "14"
   */
  public function getLibraryContentCount() {
    $contentCount = [];

    $results = $this->manager->getRepository('EmmedyH5PBundle:Content')->libraryContentCount();

    // Format results
    foreach($results as $library) {
      $contentCount[$library['machineName']." ".$library['majorVersion'].".".$library['minorVersion']] = $library['count'];
    }

    return $contentCount;
  }

    /**
   * Implements getLibraryStats
   */
  public function getLibraryStats($type) {
    $count = [];

    $results = $this->manager->getRepository('EmmedyH5PBundle:Counters')->findBy(['type' => $type]);

    // Extract results
    foreach($results as $library) {
      $count[$library->getLibraryName()." ".$library->getLibraryVersion()] = $library->getNum();
    }

    return $count;
  }

    /**
   * Implements getNumAuthors
   */
  public function getNumAuthors() {

    $contents = $this->manager->getRepository('EmmedyH5PBundle:Content')->countContent();

    // Return 1 if there is content and 0 if there is none
    return !$contents;
  }

    /**
   * Implements saveLibraryData
   *
   * @param array $libraryData
   * @param boolean $new
   */
  public function saveLibraryData(&$libraryData, $new = TRUE) {
    $preloadedJs = $this->pathsToCsv($libraryData, 'preloadedJs');
    $preloadedCss =  $this->pathsToCsv($libraryData, 'preloadedCss');
    $dropLibraryCss = '';

    if (isset($libraryData['dropLibraryCss'])) {
      $libs = array();
      foreach ($libraryData['dropLibraryCss'] as $lib) {
        $libs[] = $lib['machineName'];
      }
      $dropLibraryCss = implode(', ', $libs);
    }

    $embedTypes = '';
    if (isset($libraryData['embedTypes'])) {
      $embedTypes = implode(', ', $libraryData['embedTypes']);
    }
    if (!isset($libraryData['semantics'])) {
      $libraryData['semantics'] = '';
    }
    if (!isset($libraryData['fullscreen'])) {
      $libraryData['fullscreen'] = 0;
    }
    if (!isset($libraryData['hasIcon'])) {
      $libraryData['hasIcon'] = 0;
    }
    if ($new) {
        $library = new Library();
        $library->setTitle($libraryData['title']);
        $library->setMachineName($libraryData['machineName']);
        $library->setMajorVersion($libraryData['majorVersion']);
        $library->setMinorVersion($libraryData['minorVersion']);
        $library->setPatchVersion($libraryData['patchVersion']);
        $library->setRunnable($libraryData['runnable']);
        $library->setFullscreen($libraryData['fullscreen']);
        $library->setEmbedTypes($embedTypes);
        $library->setPreloadedJs($preloadedJs);
        $library->setPreloadedCss($preloadedCss);
        $library->setDropLibraryCss($dropLibraryCss);
        $library->setSemantics($libraryData['semantics']);
        $library->setHasIcon($libraryData['hasIcon']);

        $this->manager->persist($library);
        $this->manager->flush();

      $libraryData['libraryId'] = $library->getId();
      if ($libraryData['runnable']) {
        $h5p_first_runnable_saved = $this->getOption('first_runnable_saved', FALSE);
        if (! $h5p_first_runnable_saved) {
          $this->setOption('first_runnable_saved', 1);
        }
      }
    }
    else {
        $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->find($libraryData['libraryId']);

        $library->setTitle($libraryData['title']);
        $library->setPatchVersion($libraryData['patchVersion']);
        $library->setFullscreen($libraryData['fullscreen']);
        $library->setEmbedTypes($embedTypes);
        $library->setPreloadedJs($preloadedJs);
        $library->setPreloadedCss($preloadedCss);
        $library->setDropLibraryCss($dropLibraryCss);
        $library->setSemantics($libraryData['semantics']);
        $library->setHasIcon($libraryData['hasIcon']);

        $this->manager->persist($library);
        $this->manager->flush();

      $this->deleteLibraryDependencies($libraryData['libraryId']);
    }

    $languages = $this->manager->getRepository('EmmedyH5PBundle:LibrariesLanguages')->findBy(['library' => $library]);
      foreach ($languages as $language) {
          $this->manager->remove($language);
    }
    if (isset($libraryData['language'])) {
      foreach ($libraryData['language'] as $languageCode => $languageJson) {
          $language = new LibrariesLanguages();
          $language->setLibrary($library);
          $language->setLanguageCode($languageCode);
          $language->setLanguageJson($languageJson);

          $this->manager->persist($language);
      }
    }
    $this->manager->flush();
  }

    /**
   * Convert list of file paths to csv
   *
   * @param array $libraryData
   *  Library data as found in library.json files
   * @param string $key
   *  Key that should be found in $libraryData
   * @return string
   *  file paths separated by ', '
   */
  private function pathsToCsv($libraryData, $key) {
    if (isset($libraryData[$key])) {
      $paths = array();
      foreach ($libraryData[$key] as $file) {
        $paths[] = $file['path'];
      }
      return implode(', ', $paths);
    }
    return '';
  }

    public function lockDependencyStorage() {
//    if (db_driver() === 'mysql') {
//      // Only works for mysql, other DBs will have to use transactions.
//
//      // db_transaction often deadlocks, we do it more brutally...
//      db_query('LOCK TABLES {h5p_libraries_libraries} write, {h5p_libraries} as hl read');
//    }
  }

    public function unlockDependencyStorage() {
//    if (db_driver() === 'mysql') {
//      db_query('UNLOCK TABLES');
//    }
  }

    /**
   * Implements deleteLibraryDependencies
   */
  public function deleteLibraryDependencies($libraryId) {
      $libraries = $this->manager->getRepository('EmmedyH5PBundle:LibraryLibraries')->findBy(['library' => $libraryId]);
      foreach ($libraries as $library) {
          $this->manager->remove($library);
    }
    $this->manager->flush();
  }

    /**
   * Implements deleteLibrary. Will delete a library's data both in the database and file system
   */
  public function deleteLibrary($libraryId) {
      $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->find($libraryId);
      $this->manager->remove($library);
      $this->manager->flush();

    // Delete files
    \H5PCore::deleteFileTree($this->getRelativeH5PPath() . "/libraries/{$library->getMachineName()}-{$library->getMajorVersion()}.{$library->getMinorVersion()}");
  }

    /**
   * Implements saveLibraryDependencies
   */
  public function saveLibraryDependencies($libraryId, $dependencies, $dependencyType)
  {
        foreach ($dependencies as $dependency) {
            $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->find($libraryId);
            $requiredLibrary = $this->manager->getRepository('EmmedyH5PBundle:Library')->findOneBy(['machineName' => $dependency['machineName'], 'majorVersion' => $dependency['majorVersion'], 'minorVersion' => $dependency['minorVersion']]);
            $libraryLibraries = new LibraryLibraries();
            $libraryLibraries->setLibrary($library);
            $libraryLibraries->setRequiredLibrary($requiredLibrary);
            $libraryLibraries->setDependencyType($dependencyType);
            $this->manager->persist($libraryLibraries);
        }
        $this->manager->flush();
  }

    /**
   * Implements updateContent
   */
  public function updateContent($content, $contentMainId = NULL)
  {
      $content = $this->manager->getRepository('EmmedyH5PBundle:Content')->find($content['id']);

      $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->find($content['library']['libraryId']);
      $content->setLibrary($library);
      $content->setParameters($content['params']);
      $content->setDisabledFeatures($content['disable']);
      $content->setFilteredParameters('');

      $this->manager->persist($content);
      $this->manager->flush();
  }

    /**
   * Implements insertContent
   */
  public function insertContent($content, $contentMainId = NULL)
  {
      $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->find($content['library']['libraryId']);

      $content = new Content();
      $content->setLibrary($library);
      $content->setParameters($content['params']);
      $content->setDisabledFeatures($content['disable']);

      $this->manager->persist($content);
      $this->manager->flush();

    // Grab id of new entitu
    $content['id'] = $content->getId();

    // Return content id of the new entity
    return $content['id'];
  }

    /**
   * Implements resetContentUserData
   */
  public function resetContentUserData($contentId)
  {
      $contentUserData = $this->manager->getRepository('EmmedyH5PBundle:ContentUserData')->find(['mainContent' => $contentId]);
      $contentUserData->setData('RESET');
      $contentUserData->setTimestamp(time());
      $contentUserData->setDeleteOnContentChange(true);

      $this->manager->persist($contentUserData);
      $this->manager->flush();
  }

    /**
   * Implements getWhitelist
   */
  public function getWhitelist($isLibrary, $defaultContentWhitelist, $defaultLibraryWhitelist) {
    // Misplaced; should be done by Core.
    $h5p_whitelist = $this->getOption('whitelist', $defaultContentWhitelist);
    $whitelist = $h5p_whitelist;
    if ($isLibrary) {
      $h5p_library_whitelist_extras = $this->getOption('library_whitelist_extras', $defaultLibraryWhitelist);
      $whitelist .= ' ' . $h5p_library_whitelist_extras;
    }
    return $whitelist;

  }

    /**
   * Implements copyLibraryUsage
   */
  public function copyLibraryUsage($contentId, $copyFromId, $contentMainId = NULL) {
      $contentLibrariesFrom = $this->manager->getRepository('EmmedyH5PBundle:ContentLibraries')->find($copyFromId);
      $contentLibrariesTo = $this->manager->getRepository('EmmedyH5PBundle:ContentLibraries')->find($contentId);

      $contentLibrariesTo->setLibrary($contentLibrariesFrom->getLibrary());
      $contentLibrariesTo->setDependencyType($contentLibrariesFrom->getDependencyType());
      $contentLibrariesTo->setContent($contentLibrariesFrom->getContent());
      $contentLibrariesTo->setDropCss($contentLibrariesFrom->isDropCss());
      $contentLibrariesTo->setWeight($contentLibrariesFrom->getWeight());

      $this->manager->persist($contentLibrariesTo);
      $this->manager->flush($contentLibrariesTo);
  }

    /**
   * Implements deleteContentData
   */
  public function deleteContentData($contentId) {
    // Delete library usage
    $this->deleteLibraryUsage($contentId);

    // Remove content points
    $points = $this->manager->getRepository('EmmedyH5PBundle:Points')->findBy(['content' => $contentId]);
    $this->manager->remove($points);

    // Remove content user data
    $contentUserData = $this->manager->getRepository('EmmedyH5PBundle:ContentUserData')->findBy(['content' => $contentId]);
    $this->manager->remove($contentUserData);
    $this->manager->flush();
  }

    /**
   * Implements deleteLibraryUsage
   */
  public function deleteLibraryUsage($contentId) {

      $contentLibraries = $this->manager->getRepository('EmmedyH5PBundle:ContentLibraries')->find(['content' => $contentId]);
      $this->manager->remove($contentLibraries);
      $this->manager->flush();
  }

    /**
   * Implements saveLibraryUsage
   */
  public function saveLibraryUsage($contentId, $librariesInUse) {
      $content = $this->manager->getRepository('EmmedyH5PBundle:Content')->find($contentId);
    $dropLibraryCssList = array();
    foreach ($librariesInUse as $dependency) {
      if (!empty($dependency['library']['dropLibraryCss'])) {
        $dropLibraryCssList = array_merge($dropLibraryCssList, explode(', ', $dependency['library']['dropLibraryCss']));
      }
    }
    foreach ($librariesInUse as $dependency) {
      $dropCss = in_array($dependency['library']['machineName'], $dropLibraryCssList);
      $contentLibrary = new ContentLibraries();
      $contentLibrary->setContent($content);
      $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->find($dependency['library']['libraryId']);
      $contentLibrary->setLibrary($library);
      $contentLibrary->setWeight($dependency['weight']);
      $contentLibrary->setDropCss($dropCss);
      $contentLibrary->setDependencyType($dependency['type']);
      $this->manager->persist($contentLibrary);
    }
    $this->manager->flush();
  }

    /**
   * Implements loadLibrary
   */
  public function loadLibrary($machineName, $majorVersion, $minorVersion) {
      $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->findOneBy(['machineName' => $machineName, 'majorVersion' => $majorVersion, 'minorVersion' => $minorVersion]);
      if (!$library) {
          return false;
      }

      $libraryLibraries = $this->manager->getRepository('EmmedyH5PBundle:LibraryLibraries')->findBy(['library' => $library->getId()]);


    foreach ($libraryLibraries as $dependency) {
        $requiredLibrary = $dependency->getRequiredLibrary();
      $library["{$dependency->getDependencyType()}Dependencies"][] = [
        'machineName' => $requiredLibrary->getMachineName(),
        'majorVersion' => $requiredLibrary->getMajor(),
        'minorVersion' => $requiredLibrary->getMinor(),
      ];
    }

    return $library;
  }

    /**
   * Implements loadLibrarySemantics().
   */
  public function loadLibrarySemantics($machineName, $majorVersion, $minorVersion) {
      $library = $this->manager->getRepository('EmmedyH5PBundle:Library')->findOneBy(['machineName' => $machineName, 'majorVersion' => $majorVersion, 'minorVersion' => $minorVersion]);

      if ($library) {
          return $library->getSemantics();
      }
      return null;
  }

    /**
   * Implements alterLibrarySemantics().
   */
  public function alterLibrarySemantics(&$semantics, $name, $majorVersion, $minorVersion) {
//    // alter only takes 4 arguments, so versions are combined to single parameter
//    $version = $majorVersion . '.'. $minorVersion;
//    \Drupal::moduleHandler()->alter('h5p_semantics', $semantics, $name, $version);
      // todo: call event dispatcher here
  }

    /**
   * Implements loadContent().
   */
  public function loadContent($id) {

    // Not sure if we really need this since the content is loaded when the
    // content entity is loaded.
  }

    /**
   * Implements loadContentDependencies().
   */
  public function loadContentDependencies($id, $type = NULL)
  {
      $query = ['content' => $id];
      if ($type !== NULL) {
          $query['dependencyType'] = $type;
      }
      $contentLibraries = $this->manager->getRepository('EmmedyH5PBundle:ContentLibraries')->findBy($query, ['weight' => 'ASC']);
      $dependencies = [];
      foreach ($contentLibraries as $contentLibrary) {
          /** @var Library $library */
          $library = $contentLibrary->getLibrary();
          $dependencies[] = ['libraryId' => $library->getId(), 'machineName' => $library->getMachineName(), 'majorVersion' => $library->getMajorVersion(), 'minorVersion' => $library->getMinorVersion(),
              'patchVersion' => $library->getPatchVersion(), 'preloadedCss' => $library->getPreloadedCss(), 'preloadedJs' => $library->getPreloadedJs(), 'dropCss' => $contentLibrary->isDropCss(), 'dependencyType' => $contentLibrary->getDependencyType()];
      }

    return $dependencies;
  }


  public function getOption($name, $default = NULL) {
      return $this->options->getOption($name, $default);
  }

  public function setOption($name, $value) {
      $this->options->setOption($name, $value);
  }

    /**
   * Implements updateContentFields().
   */
  public function updateContentFields($id, $fields) {
    if (!isset($fields['filtered'])) {
      return;
    }

    $content = $this->manager->getRepository('EmmedyH5PBundle:Content')->find($id);
    $content->setFilteredParameters($fields['filtered']);
    $this->manager->persist($content);
    $this->manager->flush();
  }

    /**
   * Will clear filtered params for all the content that uses the specified
   * library. This means that the content dependencies will have to be rebuilt,
   * and the parameters refiltered.
   *
   * @param int $library_id
   */
  public function clearFilteredParameters($library_id) {

      $contents = $this->manager->getRepository('EmmedyH5PBundle:Content')->findBy(['library' => $library_id]);
      foreach ($contents as $content) {
          $content->setFilteredParameters('');
          $this->manager->persist($content);
      }
      $this->manager->flush();


//    // Clear hook_library_info_build() to use updated libraries
//    \Drupal::service('library.discovery.collector')->clear();
//
//    // Delete ALL cached JS and CSS files
//    \Drupal::service('asset.js.collection_optimizer')->deleteAll();
//    \Drupal::service('asset.css.collection_optimizer')->deleteAll();
//
//    // Reset cache buster
//    _drupal_flush_css_js();
//
//    // Clear field view cache for ALL H5P content
//    \Drupal\Core\Cache\Cache::invalidateTags(['h5p_content']);
  }

    /**
   * Get number of contents that has to get their content dependencies rebuilt
   * and parameters refiltered.
   *
   * @return int
   */
  public function getNumNotFiltered() {
      return $this->manager->getRepository('EmmedyH5PBundle:Content')->countNotFiltered();
  }

    /**
   * Implements getNumContent.
   */
  public function getNumContent($library_id) {
      return $this->manager->getRepository('EmmedyH5PBundle:Content')->countLibraryContent($library_id);
  }

    /**
   * Implements isContentSlugAvailable
   */
  public function isContentSlugAvailable($slug) {
      throw new \Exception();
//    return !db_query('SELECT slug FROM {h5p_content} WHERE slug = :slug', [':slug' => $slug])->fetchField();
  }

    /**
   * Implements saveCachedAssets
   */
  public function saveCachedAssets($key, $libraries) {
  }

    /**
   * Implements deleteCachedAssets
   */
  public function deleteCachedAssets($library_id) {
  }

    /**
   * Implements afterExportCreated
   */
  public function afterExportCreated($content, $filename) {
  }

    /**
   * Implements hasPermission
   *
   * @param H5PPermission $permission
   * @param int $content_id
   * @return bool
   */
  public function hasPermission($permission, $content_id = NULL) {

    switch ($permission) {
      case \H5PPermission::DOWNLOAD_H5P:
          $content = $this->manager->getRepository('EmmedyH5PBundle:Content')->find($content_id);
        return $content_id !== NULL && (
            $this->authorizationChecker->isGranted('ROLE_H5P_DOWNLOAD_ALL') ||
            ($this->authorizationChecker->isGranted('update', $content) && $this->authorizationChecker->isGranted('ROLE_H5P_DOWNLOAD'))
            );
      case \H5PPermission::EMBED_H5P:
          $content = $this->manager->getRepository('EmmedyH5PBundle:Content')->find($content_id);
        return $content_id !== NULL && (
            $this->authorizationChecker->isGranted('ROLE_H5P_EMBED_ALL') ||
            ($this->authorizationChecker->isGranted('update', $content) && $this->authorizationChecker->isGranted('ROLE_H5P_EMBED'))
          );

      case \H5PPermission::CREATE_RESTRICTED:
          return $this->authorizationChecker->isGranted('ROLE_H5P_CREATE_RESTRICTED_CONTENT_TYPES');

      case \H5PPermission::UPDATE_LIBRARIES:
          return $this->authorizationChecker->isGranted('ROLE_H5P_UPDATE_LIBRARIES');

      case \H5PPermission::INSTALL_RECOMMENDED:
          return $this->authorizationChecker->isGranted('ROLE_H5P_INSTALL_RECOMMENDED_LIBRARIES');
    }
    return FALSE;
  }

    /**
   * Replaces existing content type cache with the one passed in
   *
   * @param object $contentTypeCache Json with an array called 'libraries'
   *  containing the new content type cache that should replace the old one.
   */
  public function replaceContentTypeCache($contentTypeCache) {
      $this->truncateTable(LibrariesHubCache::class);

    foreach ($contentTypeCache->contentTypes as $ct) {
      $created_at = new \DateTime($ct->createdAt);
      $updated_at = new \DateTime($ct->updatedAt);

      $cache = new LibrariesHubCache();
      $cache->setMachineName($ct->id);
      $cache->setMajorVersion($ct->version->major);
      $cache->setMinorVersion($ct->version->minor);
      $cache->setPatchVersion($ct->version->patch);
      $cache->setH5pMajorVersion($ct->coreApiVersionNeeded->major);
      $cache->setH5pMinorVersion($ct->coreApiVersionNeeded->minor);
      $cache->setTitle($ct->title);
      $cache->setSummary($ct->summary);
      $cache->setDescription($ct->description);
      $cache->setIcon($ct->icon);
      $cache->setCreatedAt($created_at->getTimestamp());
      $cache->setUpdatedAt($updated_at->getTimestamp());
      $cache->setIsRecommended($ct->isRecommended);
      $cache->setPopularity($ct->popularity);
      $cache->setScreenshots(json_encode($ct->screenshots));
      $cache->setLicense(json_encode(isset($ct->license) ? $ct->license : []));
      $cache->setExample($ct->example);
      $cache->setTutorial(isset($ct->tutorial) ? $ct->tutorial : '');
      $cache->setKeywords(json_encode(isset($ct->keywords) ? $ct->keywords : []));
      $cache->setCategories(json_encode(isset($ct->categories) ? $ct->categories : []));
      $cache->setOwner($ct->owner);

      $this->manager->persist($cache);
    }
    $this->manager->flush();
  }

    private function truncateTable($tableClassName)
    {
        $cmd = $this->manager->getClassMetadata($tableClassName);
        $connection = $this->manager->getConnection();
        $dbPlatform = $connection->getDatabasePlatform();
        $connection->query('SET FOREIGN_KEY_CHECKS=0');
        $q = $dbPlatform->getTruncateTableSql($cmd->getTableName());
        $connection->executeUpdate($q);
        $connection->query('SET FOREIGN_KEY_CHECKS=1');
    }
}