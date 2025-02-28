<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\View\Main;

use Akeeba\Panopticon\Model\Site;
use Akeeba\Panopticon\Model\Usagestats;
use Awf\Pagination\Pagination;
use Awf\Utils\Template;

defined('AKEEBA') || die;

class Html extends \Awf\Mvc\DataView\Html
{
	public string $cronKey;

	public ?int $cronSecondsBehind = 0;

	public array $groupMap = [];

	protected function onBeforeMain()
	{
		$this->setStrictLayout(true);
		$this->setStrictTpl(true);

		// Groups map
		$this->groupMap = $this->getModel('groups')->getGroupMap();

		// Create the lists object
		$this->lists = new \stdClass();

		// Load the model
		/** @var Site $model */
		$model = $this->getModel();
		$model->setState('enabled', 1);

		// We want to persist the state in the session
		$model->savestate(1);

		// Ordering information
		$this->lists->order     = $model->getState('filter_order', 'name', 'cmd');
		$this->lists->order_Dir = $model->getState('filter_order_Dir', 'ASC', 'cmd');

		// Display limits
		$this->lists->limitStart = $model->getState('limitstart', 0, 'int');
		$this->lists->limit      = $model->getState('limit', 50, 'int');

		$model->setState('filter_order', $this->lists->order);
		$model->setState('filter_order_Dir', $this->lists->order_Dir);
		$model->setState('limitstart', $this->lists->limitStart);
		$model->setState('limit', $this->lists->limit);

		// How far behind are the CRON jobs?
		$this->cronSecondsBehind = $this->getContainer()->mvcFactory->makeTempModel('main')
			->getCRONJobsSecondsBehind();

		// Assign items to the view
		$this->items      = $model->get();
		$this->itemsCount = $model->count();

		// Pagination
		$displayedLinks   = 10;
		$this->pagination = new Pagination($this->itemsCount, $this->lists->limitStart, $this->lists->limit, $displayedLinks, $this->container);

		// Back button in the CRON instructions page
		if ($this->layout === 'cron')
		{
			$this->cronKey = $this->container->appConfig->get('webcron_key', '');

			$this->container->application->getDocument()->getToolbar()->addButtonFromDefinition([
				'id'    => 'prev',
				'title' => $this->getLanguage()->text('PANOPTICON_BTN_PREV'),
				'class' => 'btn btn-secondary border-light',
				'url'   => $this->container->router->route('index.php'),
				'icon'  => 'fa fa-chevron-left',
			]);
		}

		// JavaScript
		/** @var Usagestats $usageStatsModel */
		$usageStatsModel = $this->getModel('usagestats');

		$doc = $this->container->application->getDocument();
		$doc->addScriptOptions('panopticon.heartbeat', [
			'url'       => $this->container->router->route('index.php?view=main&task=heartbeat&format=json'),
			'warningId' => 'heartbeatWarning',
		]);
		$doc->addScriptOptions('panopticon.usagestats', [
			'url'     => $this->container->router->route('index.php?view=usagestats&task=ajax&format=raw'),
			'enabled' => $usageStatsModel->isStatsCollectionEnabled(),
		]);

		Template::addJs('media://js/main.js', $this->getContainer()->application, defer: true);

		// Toolbar
		$toolbar = $doc->getToolbar();
		$toolbar->setTitle($this->getLanguage()->text('PANOPTICON_MAIN_TITLE'));
		$toolbar->addButtonFromDefinition([
			'id'      => 'manageSites',
			'title' => $this->getLanguage()->text('PANOPTICON_MAIN_SITES_LBL_MY_SITES_MANAGE'),
			'class' => 'btn btn-secondary border-light',
			'url'   => $this->container->router->route('index.php?view=sites'),
			'icon'  => 'fa fa-gears',
		]);

		return true;
	}
}