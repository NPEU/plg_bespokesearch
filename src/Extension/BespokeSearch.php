<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Finder.BespokeSearch
 *
 * @copyright   Copyright (C) NPEU 2024.
 * @license     MIT License; see LICENSE.md
 */

namespace NPEU\Plugin\Finder\BespokeSearch\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Categories\Categories;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Table\Table;
use Joomla\Component\Finder\Administrator\Indexer\Adapter;
use Joomla\Component\Finder\Administrator\Indexer\Helper;
use Joomla\Component\Finder\Administrator\Indexer\Indexer;
use Joomla\Component\Finder\Administrator\Indexer\Result;
use Joomla\Component\Weblinks\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\DatabaseQuery;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;


/**
 * Allows indexing of certain Bespoke modules.
 */
final class BespokeSearch extends Adapter
{
    use DatabaseAwareTrait;

    /**
     * An internal flag whether plugin should listen any event.
     *
     * @var bool
     *
     * @since   4.3.0
     */
    protected static $enabled = false;

    /**
     * The plugin identifier.
     *
     * @var    string
     * @since  2.5
     */
    protected $context = 'BespokeSearch';

    /**
     * The extension name.
     *
     * @var    string
     * @since  2.5
     */
    protected $extension = 'com_bespoke';

    /**
     * The sublayout to use when rendering the results.
     *
     * @var    string
     * @since  2.5
     */
    #protected $layout = 'weblink';

    /**
     * The type of content that the adapter indexes.
     *
     * @var    string
     * @since  2.5
     */
    protected $type_title = 'BespokeSearch';

    /**
     * The table name.
     *
     * @var    string
     * @since  2.5
     */
    #protected $table = '#__bespoke';

    /**
     * Load the language file on instantiation.
     *
     * @var    boolean
     * @since  3.1
     */
    protected $autoloadLanguage = true;

    /**
     * Constructor
     *
     * @param   DispatcherInterface  $dispatcher
     * @param   array                $config
     * @param   DatabaseInterface    $database
     */
    public function __construct(DispatcherInterface $dispatcher, array $config, DatabaseInterface $database)
    {
        self::$enabled = true;

        parent::__construct($dispatcher, $config);

        $this->setDatabase($database);
    }

        /**
     * Method to get the MenuItems.
     *
     *
     * @return  array  Array of objects.
     */
    protected function getMenuItems()
    {
        $db = $this->getDatabase();

        // Get the COM_DESPOKE component id:
        $query = $db->getQuery(true);
        $query->select($db->quoteName('extension_id'))
              ->from($db->quoteName('#__extensions'))
              ->where($db->quoteName('name') . ' = ' . $db->quote('COM_BESPOKE'));
        $db->setQuery($query);

        $component_id = $db->loadResult();

        // Get all Bespoke menu items:
        $query = $db->getQuery(true);
        $query->select($db->quoteName(array('id', 'title', 'alias', 'path', 'link', 'params')))
              ->from($db->quoteName('#__menu'))
              ->where($db->quoteName('component_id') . ' = ' . $component_id)
              ->andwhere($db->quoteName('access') . ' = 1')
              ->andwhere($db->quoteName('published') . ' = 1');
        $db->setQuery($query);

        $menu_items = $db->loadAssocList();

        foreach ($menu_items as $key => &$menu_item) {
            // Get all assigned module ids:
            $module_ids = array();
            $menu_item_params = json_decode($menu_item['params']);

            if (empty($menu_item_params->blocks)) {
                unset($menu_items[$key]);
                continue;
            }

            foreach ($menu_item_params->blocks as $block) {
                if (!empty($block->leftpane)) {
                    $module_ids[] = $block->leftpane;
                }
                if (!empty($block->rightpane)) {
                    $module_ids[] = $block->rightpane;
                }
            }
            unset($menu_item['params']);

            // Get all module data:
            $query = $db->getQuery(true);
            $query->select('*')
                  ->from($db->quoteName('#__modules'))
                  ->where($db->quoteName('id') . ' IN (' . implode(',', $module_ids) . ')');
            $db->setQuery($query);

            $modules = $db->loadAssocList();

            $start_date = false;
            $summary = '';
            foreach ($modules as $module) {
                $module_params = json_decode($module['params']);
                // Handle different types separately:
                if ($module['module'] == 'mod_text') {
                    $summary .= $module['content'] . "\n\n";
                } elseif ($module['module'] == 'mod_funder') {
                    $summary .= $module->params->statement . "\n\n";
                } else {}

                // Establish earliest start date:
                $start_date = ($start_date == false)
                            ? $module['publish_up']
                            : min($start_date, $module['publish_up']);
            }

            if (empty($summary)) {
                unset($menu_items[$key]);
                continue;
            }

            $menu_item['summary']    = $summary;
            $menu_item['start_date'] = $start_date;
        }
        return $menu_items;
    }

    /**
     * Method to get a list of content items to index.
     *
     * @param   integer         $offset  The list offset.
     * @param   integer         $limit   The list limit.
     * @param   QueryInterface  $query   A QueryInterface object. [optional]
     *
     * @return  Result[]  An array of Result objects.
     *
     * @since   2.5
     * @throws  \Exception on database error.
     */
    protected function getItems($offset, $limit, $query = null)
    {
        $items = [];

        // Get the content items to index.
        //$this->db->setQuery($this->getListQuery($query), $offset, $limit);
        //$rows = $this->db->loadAssocList();
        $rows = $this->getMenuItems();

        // Convert the items to result objects.
        foreach ($rows as $row) {
            // Convert the item to a result object.
            $item = ArrayHelper::toObject($row, Result::class);

            // Sort out endcoding stuff:
            #$item->summary  = $this->utf8_convert($item->summary);

            // Set the item type.
            $item->type_id = $this->type_id;

            // Set the mime type.
            $item->mime = $this->mime;

            // Set the item layout.
            $item->layout = $this->layout;

            // Set the extension if present
            if (isset($row->extension)) {
                $item->extension = $row->extension;
            }

            $item->url    = $item->path;
            $item->route  = $item->path;
            $item->state  = 1;
            $item->access = 1;

            // Add the item to the stack.
            $items[] = $item;
        }
        return $items;
    }

    /**
     * Method to index an item. The item must be a Result object.
     *
     * @param   Result  $item  The item to index as an Result object.
     *
     * @return  void
     *
     * @throws  \Exception on database error.
     * @since   2.5
     */
    protected function index(Result $item)
    {
        // Check if the extension is enabled
        if (ComponentHelper::isEnabled($this->extension) == false) {
            return;
        }

        $item->setLanguage();
        $this->indexer->index($item);
    }

    /**
     * Method to setup the indexer to be run.
     *
     * @return  boolean  True on success.
     *
     * @since   2.5
     */
    protected function setup()
    {
        return true;
    }
}