<?php
/**
 * @package     Joomla.Plugin
 * @subpackage  Finder.BespokeSearch
 *
 * @copyright   Copyright (C) NPEU 2019.
 * @license     MIT License; see LICENSE.md
 */

defined('_JEXEC') or die;

require_once JPATH_ADMINISTRATOR . '/components/com_finder/helpers/indexer/adapter.php';

/**
 * Allows indexing of certain Bespoke modules.
 */
class plgFinderBespokeSearch extends FinderIndexerAdapter
{
    protected $autoloadLanguage = true;

    /**
     * The extension name.
     *
     * @var    string
     */
    protected $extension = 'com_bespoke';

    /**
     * The type of content that the adapter indexes.
     *
     * @var    string
     */
    protected $type_title = 'Bespokesearch';

    /**
     * Method to setup the adapter before indexing.
     *
     * @return  boolean  True on success, false on failure.
     *
     * @throws  Exception on database error.
     */
    protected function setup()
    {
        // CLI indexer throws lots of notices about JPATH_COMPONENT so defining here.
        // Note this is probably only CLI-specific, and is in place to make it easier to see
        // problems in THIS plugin when testing via the CLI, so keep commented unless testing.
        /*if (!defined('JPATH_COMPONENT')) {
            define('JPATH_COMPONENT', '');
        }*/
        return true;
    }

    /**
     * Method to index an item.
     *
     * @param   FinderIndexerResult  $item  The item to index as a FinderIndexerResult object.
     *
     * @return  boolean  True on success.
     *
     * @throws  Exception on database error.
     */
    protected function index(FinderIndexerResult $item)
    {
        // Check if the extension is enabled
        if (JComponentHelper::isEnabled($this->extension) == false) {
            return;
        }

        $this->indexer->index($item);
    }

    /**
     * Method to get the MenuItems.
     *
     *
     * @return  array  Array of objects.
     */
    protected function getMenuItems()
    {
        $db = JFactory::getDbo();

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
     * @param   JDatabaseQuery  $query   A JDatabaseQuery object. [optional]
     *
     * @return  array  An array of FinderIndexerResult objects.
     *
     * @throws  Exception on database error.
     */
    protected function getItems($offset, $limit, $query = null)
    {
        $items = array();

        // Get the content items to index.
        //$this->db->setQuery($this->getListQuery($query), $offset, $limit);
        //$rows = $this->db->loadAssocList();
        $rows = $this->getMenuItems();

        #var_dump($rows); exit;

        // Convert the items to result objects.
        foreach ($rows as $row) {
            // Convert the item to a result object.
            $item = JArrayHelper::toObject($row, 'FinderIndexerResult');

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
     * Method to convert utf8 characters.
     * (not currently used but keep in case)
     *
     * @param   string   $text  The text to convert.
     *
     * @return  string
     *
     * @since   2.5
     */
    protected function utf8_convert($text)
    {
        if (!is_string($text)) {
            trigger_error('Function \'utf8_convert\' expects argument 1 to be a string', E_USER_ERROR);
            return false;
        }
        // Only do the slow convert if there are 8-bit characters
        // Avoid using 0xA0 (\240) in ereg ranges. RH73 does not like that
        if (!preg_match("[\200-\237]", $text) && !preg_match("[\241-\377]", $text)) {
            return $text;
        }
        // Decode three byte unicode characters
        $text = preg_replace("/([\340-\357])([\200-\277])([\200-\277])/e", "'&#'.((ord('\\1')-224)*4096 + (ord('\\2')-128)*64 + (ord('\\3')-128)).';'", $text);
        // Decode two byte unicode characters
        $text = preg_replace("/([\300-\337])([\200-\277])/e", "'&#'.((ord('\\1')-192)*64+(ord('\\2')-128)).';'", $text);
        return $text;
    }
}
