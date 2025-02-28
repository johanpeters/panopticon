<?php
/**
 * @package   panopticon
 * @copyright Copyright (c)2023-2023 Nicholas K. Dionysopoulos / Akeeba Ltd
 * @license   https://www.gnu.org/licenses/agpl-3.0.txt GNU Affero General Public License, version 3 or later
 */

namespace Akeeba\Panopticon\Model;

defined('AKEEBA') || die;

use Akeeba\Panopticon\Exception\SiteConnection\APIApplicationHasPHPMessages;
use Akeeba\Panopticon\Exception\SiteConnection\APIApplicationIsBlocked;
use Akeeba\Panopticon\Exception\SiteConnection\APIApplicationIsBroken;
use Akeeba\Panopticon\Exception\SiteConnection\APIInvalidCredentials;
use Akeeba\Panopticon\Exception\SiteConnection\cURLError;
use Akeeba\Panopticon\Exception\SiteConnection\FrontendPasswordProtection;
use Akeeba\Panopticon\Exception\SiteConnection\InvalidHostName;
use Akeeba\Panopticon\Exception\SiteConnection\PanopticonConnectorNotEnabled;
use Akeeba\Panopticon\Exception\SiteConnection\SelfSignedSSL;
use Akeeba\Panopticon\Exception\SiteConnection\SSLCertificateProblem;
use Akeeba\Panopticon\Exception\SiteConnection\WebServicesInstallerNotEnabled;
use Akeeba\Panopticon\Library\Task\Status;
use Akeeba\Panopticon\Model\Trait\AdminToolsIntegrationTrait;
use Akeeba\Panopticon\Model\Trait\AkeebaBackupIntegrationTrait;
use Akeeba\Panopticon\Model\Trait\ApplyUserGroupsToSiteQueryTrait;
use Akeeba\Panopticon\Task\RefreshSiteInfo;
use Akeeba\Panopticon\Task\Trait\ApiRequestTrait;
use Akeeba\Panopticon\Task\Trait\JsonSanitizerTrait;
use Awf\Container\Container;
use Awf\Date\Date;
use Awf\Mvc\DataModel;
use Awf\Registry\Registry;
use Awf\Uri\Uri;
use Awf\User\User;
use Awf\Utils\ArrayHelper;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Utils;
use GuzzleHttp\RequestOptions;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Defines a site known to Panopticon
 *
 * @property int         $id           Task ID.
 * @property string      $name         The name of the site (user-visible).
 * @property string      $url          The URL to the site (with the /api part).
 * @property int         $enabled      Is this site enabled?
 * @property Date        $created_on   When was this site created?
 * @property int         $created_by   Who created this site?
 * @property null|Date   $modified_on  When was this site last modified?
 * @property null|int    $modified_by  Who last modified this site?
 * @property null|Date   $locked_on    When was this site last locked for writing?
 * @property null|int    $locked_by    Who last locked this site for writing?
 * @property Registry    $config       The configuration for this site.
 * @property null|string $notes        Freeform notes (in HTML).
 *
 * @since  1.0.0
 */
class Site extends DataModel
{
	use ApiRequestTrait;
	use AkeebaBackupIntegrationTrait;
	use AdminToolsIntegrationTrait;
	use ApplyUserGroupsToSiteQueryTrait;
	use JsonSanitizerTrait;

	public function __construct(Container $container = null)
	{
		$this->tableName   = '#__sites';
		$this->idFieldName = 'id';

		parent::__construct($container);

		$this->fieldsSkipChecks[] = 'enabled';

		$this->addBehaviour('filters');
	}

	public function keyedList(bool $onlyEnabled = true): array
	{
		$db    = $this->getDbo();
		$query = $db->getQuery(true)
			->select(
				[
					$db->quoteName('id'),
					$db->quoteName('name'),
				]
			)
			->from($db->quoteName('#__sites'));

		if ($onlyEnabled)
		{
			$query->where(
				$db->quoteName('enabled') . ' = 1'
			);
		}

		try
		{
			return $db->setQuery($query)->loadAssocList('id', 'name') ?: [];
		}
		catch (Exception $e)
		{
			return [];
		}
	}

	public function buildQuery($overrideLimits = false)
	{
		$query = parent::buildQuery($overrideLimits);

		$this->applyUserGroupsToQuery($query);

		$db = $this->container->db;

		// Filter: search
		$fltSearch = $this->getState('search', null, 'string');

		if (!empty(trim($fltSearch ?? '')))
		{
			$fltSearch = trim($fltSearch ?? '');

			$query->andWhere(
				[
					$db->quoteName('name') . ' LIKE ' . $db->quote('%' . $fltSearch . '%'),
					$db->quoteName('url') . ' LIKE ' . $db->quote('%' . $fltSearch . '%'),
				]
			);
		}

		// Filter: has potential core updates
		$fltCoreUpdates = $this->getState('coreUpdates', '', 'cmd');

		if ($fltCoreUpdates)
		{
			$query->where(
				[
					$query->jsonExtract($db->quoteName('config'), '$.core.canUpgrade') . ' = TRUE',
					$query->jsonExtract($db->quoteName('config'), '$.latest.version') . ' != ' .
					$query->jsonExtract($db->quoteName('config'), '$.current.version'),
				]
			);
		}
		elseif (!$fltCoreUpdates && $fltCoreUpdates !== '')
		{
			$query->andWhere(
				[
					$query->jsonExtract($db->quoteName('config'), '$.core.canUpgrade') . ' = FALSE',
					$query->jsonExtract($db->quoteName('config'), '$.core.latest.version') . ' = ' .
					$query->jsonExtract($db->quoteName('config'), '$.core.current.version'),
				]
			);
		}

		// Filter: has potential extension updates
		$fltExtUpdates = $this->getState('extUpdates', '', 'cmd');

		if ($fltExtUpdates == 1)
		{
			$query->where(
				[
					$query->jsonExtract($db->quoteName('config'), '$.extensions.hasUpdates') . ' = 1',
				]
			);
		}
		elseif ($fltExtUpdates == 0 && $fltExtUpdates !== '')
		{
			$query->where(
				[
					$query->jsonExtract($db->quoteName('config'), '$.extensions.hasUpdates') . ' = 0',
				]
			);
		}

		// Filter: cmsFamily
		$fltCmsFamily = $this->getState('cmsFamily', null, 'cmd');

		if ($fltCmsFamily)
		{
			$query->where(
				$query->jsonExtract($db->quoteName('config'), '$.core.current.version') . ' LIKE ' .
				$query->quote('"' . $fltCmsFamily . '.%')
			);
		}

		// Filter: phpFamily
		$fltPHPFamily = $this->getState('phpFamily', null, 'cmd');

		if ($fltPHPFamily)
		{
			$query->where(
				$query->jsonExtract($db->quoteName('config'), '$.core.php') . ' LIKE ' . $query->quote(
					'"' . $fltPHPFamily . '.%'
				)
			);
		}

		// Filter: group
		$fltGroup = $this->getState('group', null) ?: [];

		if (!empty($fltGroup))
		{
			$fltGroup = is_string($fltGroup) && str_contains($fltGroup, ',') ? explode(',', $fltGroup) : $fltGroup;
			$fltGroup = is_array($fltGroup) ? $fltGroup : [trim($fltGroup)];
			$fltGroup = ArrayHelper::toInteger($fltGroup);
			$fltGroup = array_filter($fltGroup);
			$clauses  = [];

			foreach ($fltGroup as $gid)
			{
				$clauses[] = $query->jsonContains(
					$query->quoteName('config'), $query->quote('"' . (int) $gid . '"'), $query->quote('$.config.groups')
				);
				$clauses[] = $query->jsonContains(
					$query->quoteName('config'), $query->quote((int) $gid), $query->quote('$.config.groups')
				);
			}

			if (!empty($clauses))
			{
				$query->extendWhere('AND', $clauses, 'OR');
			}
		}

		return $query;
	}

	public function check()
	{
		$user        = $this->getContainer()->userManager->getUser();
		$uid         = $user->getId();
		$currentDate = $this->getContainer()->dateFactory()->toSql();

		$createdOn  = $this->getFieldValue('created_on', null);
		$createdBy  = $this->getFieldValue('created_by', null);
		$modifiedOn = $this->getFieldValue('modified_on', null);
		$modifiedBy = $this->getFieldValue('modified_by', null);

		if (empty($createdBy))
		{
			$createdOn  = $currentDate;
			$createdBy  = $uid;
			$modifiedBy = null;
			$modifiedOn = null;
		}
		else
		{
			$modifiedOn = $currentDate;
			$modifiedBy = $uid;
		}

		$this->setFieldValue('created_on', $createdOn);
		$this->setFieldValue('created_by', $createdBy);
		$this->setFieldValue('modified_on', $modifiedOn);
		$this->setFieldValue('modified_by', $modifiedBy);

		$this->setFieldValue('name', trim($this->getFieldValue('name', '') ?: ''));

		if (empty($this->getFieldValue('name', '')))
		{
			throw new RuntimeException($this->getLanguage()->text('PANOPTICON_SITES_ERR_NO_TITLE'));
		}

		if (empty($this->url))
		{
			throw new RuntimeException($this->getLanguage()->text('PANOPTICON_SITES_ERR_NO_URL'));
		}

		parent::check();

		$this->url = $this->cleanUrl($this->url);

		return $this;
	}

	public function testConnection(bool $getWarnings = true): array
	{
		/** @var \Akeeba\Panopticon\Container $container */
		$container = $this->container;
		$client    = $container->httpFactory->makeClient(cache: false, singleton: false);

		$session = $this->getContainer()->segment;
		$session->set('testconnection.step', null);
		$session->set('testconnection.http_status', null);
		$session->set('testconnection.body', null);
		$session->set('testconnection.headers', null);
		$session->set('testconnection.exception.type', null);
		$session->set('testconnection.exception.message', null);
		$session->set('testconnection.exception.file', null);
		$session->set('testconnection.exception.line', null);
		$session->set('testconnection.exception.trace', null);

		// Try to get index.php/v1/extensions unauthenticated
		try
		{
			$totalTimeout   = max(30, $this->container->appConfig->get('max_execution', 60) / 2);
			$connectTimeout = max(5, $totalTimeout / 5);

			$options                                  = $container->httpFactory->getDefaultRequestOptions();
			$options[RequestOptions::HEADERS]         = [
				'Accept'     => 'application/vnd.api+json',
				'User-Agent' => 'panopticon/' . AKEEBA_PANOPTICON_VERSION,
			];
			$options[RequestOptions::HTTP_ERRORS]     = false;
			$options[RequestOptions::CONNECT_TIMEOUT] = $connectTimeout;
			$options[RequestOptions::TIMEOUT]         = $totalTimeout;

			$session->set('testconnection.step', 'Unauthenticated access (can I even access the API at all?)');

			[$url,] = $this->getRequestOptions($this, '/index.php/v1/extensions');

			$response = $client->get($url, $options);
		}
		catch (GuzzleException $e)
		{
			$this->updateDebugInfoInSession(null, null, $e);

			$message = $e->getMessage();

			if (str_contains($message, 'self-signed certificate'))
			{
				throw new SelfSignedSSL('Self-signed certificate', previous: $e);
			}

			if (str_contains($message, 'SSL certificate problem'))
			{
				throw new SSLCertificateProblem('SSL certificate problem', previous: $e);
			}

			if (str_contains($message, 'Could not resolve host'))
			{
				$hostname = empty($this->url) ? '(no host provided)' : (new Uri($this->url))->getHost();
				throw new InvalidHostName(sprintf('Invalid hostname %s', $hostname));
			}

			// DO NOT MOVE! We also use the same flash variable to report Guzzle errors
			$this->container->segment->setFlash('site_connection_curl_error', $e->getMessage());

			if (str_contains($message, 'cURL error'))
			{
				throw new cURLError('Miscellaneous cURL Error', previous: $e);
			}

			// If we have no response object something went _really_ wrong. Throw it back and let the front-end handle it.
			if (!isset($response))
			{
				$this->container->segment->setFlash('site_connection_guzzle_error', $e->getMessage());

				throw $e;
			}
		}

		$bodyContent = $bodyContent ?? $response?->getBody()?->getContents();
		$this->updateDebugInfoInSession($response ?? null, $bodyContent, $e ?? null);

		if (!isset($response))
		{
			throw new RuntimeException('No response to the unauthenticated API request probe.', 500);
		}

		if ($response->getStatusCode() === 403)
		{
			throw new APIApplicationIsBlocked('The API application is blocked (403)');
		}
		elseif ($response->getStatusCode() === 404)
		{
			throw new WebServicesInstallerNotEnabled(
				'Cannot list installed extensions. Web Services - Installer is not enabled.'
			);
		}
		elseif ($response->getStatusCode() !== 401)
		{
			$this->container->segment->setFlash('site_connection_http_code', $response->getStatusCode());

			if (!str_contains($bodyContent, '{"errors":[{"title":"Forbidden"}]}'))
			{
				throw new APIApplicationIsBroken(
					sprintf('The API application does not work property (HTTP %d)', $response->getStatusCode())
				);
			}

			$canWorkAround = $this->jsonValidate($this->sanitizeJson($bodyContent));

			if (!$canWorkAround)
			{
				throw new APIApplicationHasPHPMessages();
			}
		}

		// Try to access index.php/v1/extensions **authenticated**
		[$url, $options] = $this->getRequestOptions($this, '/index.php/v1/extensions?page[limit]=2000');
		$options[RequestOptions::HTTP_ERRORS] = false;

		$session->set('testconnection.step', 'Authenticated access (can I get information out of the API?)');

		try
		{
			$response    = $client->get($url, $options);
			$bodyContent = $response?->getBody()?->getContents();
		}
		catch (GuzzleException $e)
		{
			$this->updateDebugInfoInSession($response ?? null, $bodyContent, $e);

			throw $e;
		}

		$this->updateDebugInfoInSession($response ?? null, $bodyContent, $e ?? null);

		if (!isset($response))
		{
			throw new RuntimeException('No response to the authenticated API request probe.', 500);
		}

		if ($response->getStatusCode() === 403)
		{
			throw new APIApplicationIsBlocked('The API application is blocked (403)');
		}
		elseif ($response->getStatusCode() === 404)
		{
			throw new WebServicesInstallerNotEnabled(
				'Cannot list installed extensions. Web Services - Installer is not enabled.'
			);
		}
		elseif ($response->getStatusCode() === 401)
		{
			try
			{
				$temp = @json_decode($this->sanitizeJson($bodyContent), true);
			}
			catch (Exception $e)
			{
				$temp = null;
			}

			if (
				is_array($temp) && isset($temp['errors']) && is_array($temp['errors'])
				&& isset($temp['errors'][0])
				&& is_array($temp['errors'][0])
				&& isset($temp['errors'][0]['code'])
				&& $temp['errors'][0]['code'] == 401
			)
			{
				throw new APIInvalidCredentials('The API Token is invalid');
			}

			throw new FrontendPasswordProtection();
		}
		elseif ($response->getStatusCode() !== 200)
		{
			$this->container->segment->setFlash('site_connection_http_code', $response->getStatusCode());

			throw new APIApplicationIsBroken(
				sprintf('The API application does not work property (HTTP %d)', $response->getStatusCode())
			);
		}

		try
		{
			$results = @json_decode($this->sanitizeJson($bodyContent ?? '{}'));
		}
		catch (Throwable $e)
		{
			$results = new stdClass();
		}

		if (empty($results?->data))
		{
			throw new WebServicesInstallerNotEnabled(
				'Cannot list installed extensions. Web Services - Installer is not enabled.'
			);
		}

		// Check if Panopticon is enabled
		$allEnabled = array_reduce(
			array_filter(
				$results->data,
				fn(object $data) => str_contains($data->attributes?->name ?? '', 'Panopticon')
			),
			fn(bool $carry, object $data) => $carry
			                                 && (($data->attributes?->status ?? null) == 1
			                                     || $data->attributes?->enabled == 1),
			true
		);

		if (!$allEnabled)
		{
			throw new PanopticonConnectorNotEnabled('The Panopticon Connector component or plugin is not enabled');
		}

		if (!$getWarnings)
		{
			return [];
		}

		$warnings = [];

		// Check if Akeeba Backup and its API plugin are enabled
		$allEnabled = array_reduce(
			array_filter(
				$results->data,
				fn(object $data) => str_contains($data->attributes?->name ?? '', 'Akeeba Backup')
				                    && (
					                    $data->attributes?->type === 'component'
					                    || (
						                    $data->attributes?->type === 'plugin'
						                    && $data->attributes?->folder === 'webservices'
					                    )
				                    )
			),
			fn(bool $carry, object $data) => $carry && $data->attributes?->status == 1,
			true
		);

		if (!$allEnabled)
		{
			$warnings[] = 'akeebabackup';
		}

		// Check for Admin Tools component
		$allEnabled = array_reduce(
			array_filter(
				$results->data,
				fn(object $data) => str_contains($data->attributes?->name ?? '', 'Admin Tools')
				                    && (
					                    $data->attributes?->type === 'component'
					                    || (
						                    $data->attributes?->type === 'plugin'
						                    && $data->attributes?->folder === 'system'
					                    )
				                    )
			),
			fn(bool $carry, object $data) => $carry && $data->attributes?->status == 1,
			true
		);

		if (!$allEnabled)
		{
			$warnings[] = 'admintools';
		}


		$session->set('testconnection.step', null);
		$this->updateDebugInfoInSession(null, null, null);

		return $warnings;
	}

	/**
	 * Get the base URL of the site (instead of the API endpoint).
	 *
	 * @return  string
	 */
	public function getBaseUrl(): string
	{
		$url = rtrim($this->url, "/ \t\n\r\0\x0B");

		if (str_ends_with($url, '/panopticon_api'))
		{
			$url = rtrim(substr($url, 0, -15), '?/');

			if (str_ends_with($url, '/index.php'))
			{
				$url = substr($url, 0, -10);
			}
		}
		elseif (str_ends_with($url, '/api'))
		{
			$url = rtrim(substr($url, 0, -4), '/');
		}

		return $url;
	}

	/**
	 * Get the site administration interface login URL.
	 *
	 * If Admin Tools is installed this includes the secret word.
	 *
	 * @return  string
	 * @since   1.0.0
	 */
	public function getAdminUrl(): string
	{
		$url    = $this->getBaseUrl() . '/administrator';
		$config = $this->getConfig();

		if (!$config->get('core.admintools.enabled', false) || $config->get('core.admintools.renamed', false))
		{
			return $url;
		}

		$adminDir = $config->get('core.admintools.admindir', 'administrator');

		if (!empty($adminDir) && $adminDir !== 'administrator')
		{
			$url = $this->getBaseUrl() . '/' . trim($adminDir, '/');
		}

		$secretWord = trim($config->get('core.admintools.secret_word', '') ?: '');

		if (!empty($secretWord))
		{
			if (empty($adminDir) || $adminDir === 'administrator')
			{
				$url .= '/index.php';
			}

			$url .= '?' . urlencode($secretWord);
		}

		return $url;
	}

	public function getAPIEndpointURL(): string
	{
		return rtrim($this->url, "/ \t\n\r\0\x0B");
	}

	public function fixCoreUpdateSite(): void
	{
		/** @var \Akeeba\Panopticon\Container $container */
		$container = $this->container;
		$client    = $container->httpFactory->makeClient(cache: false, singleton: false);

		[$url, $options] = $this->getRequestOptions($this, '/index.php/v1/panopticon/core/update');

		$client->post($url, $options);
	}

	/**
	 * Get the user groups which can be applied by the given user to this site
	 *
	 * @param   User|null  $user  The user. NULL for currently logged in user.
	 *
	 * @return  array Keyed array of id=>title, i.e. [id=>title, ...]
	 */
	public function getGroupsForSelect(?User $user = null, bool $includeEmpty = false): array
	{
		$user        ??= $this->container->userManager->getUser();
		$groupFilter = [];

		// If it's not a Super User I need to filter which user groups I am going to present.
		if (!$user->getPrivilege('panopticon.super'))
		{
			$groupFilter = array_keys($user->getGroupPrivileges());

			if (empty($groupFilter))
			{
				return [];
			}
		}

		$db    = $this->getDbo();
		$query = $db
			->getQuery(true)
			->select(
				[
					$db->quoteName('id'),
					$db->quoteName('title'),
				]
			)
			->from($db->quoteName('#__groups'));

		if (!empty($groupFilter))
		{
			$query->where($db->quoteName('id') . ' IN(' . implode(',', array_map([$db, 'quote'], $groupFilter)) . ')');
		}

		$ret = array_map(fn($x) => $x->title, $db->setQuery($query)->loadObjectList('id') ?: []);

		if ($includeEmpty)
		{
			$ret[''] = $this->getLanguage()->text('PANOPTICON_SITES_LBL_GROUPS_PLACEHOLDER');
		}

		return $ret;
	}

	public function getExtensionsUpdateTask(): ?Task
	{
		return $this->getSiteSpecificTask('extensionsupdate');
	}

	public function getJoomlaUpdateTask(): ?Task
	{
		return $this->getSiteSpecificTask('joomlaupdate');
	}

	public function isExtensionsUpdateTaskStuck(): bool
	{
		return $this->isSiteSpecificTaskStuck('extensionsupdate');
	}

	public function isJoomlaUpdateTaskStuck(): bool
	{
		return $this->isSiteSpecificTaskStuck('joomlaupdate');
	}

	public function isExtensionsUpdateTaskScheduled(): bool
	{
		return $this->isSiteSpecificTaskScheduled('extensionsupdate');
	}

	public function isJoomlaUpdateTaskScheduled(): bool
	{
		return $this->isSiteSpecificTaskScheduled('joomlaupdate');
	}

	public function isJoomlaUpdateTaskRunning(): bool
	{
		return $this->isSiteSpecificTaskRunning('joomlaupdate');
	}

	public function isExtensionsUpdateTaskRunning(): bool
	{
		return $this->isSiteSpecificTaskRunning('extensionsupdate');
	}

	public function getExtensionsList(bool $sortByName = true): array
	{
		$config     = $this->getConfig();
		$extensions = (array) $config->get('extensions.list', []);
		$extensions = $extensions ?: [];

		if ($sortByName)
		{
			uasort($extensions, fn($a, $b) => $a->name <=> $b->name);
		}

		return $extensions;
	}

	public function getExtensionsScheduledForUpdate(): array
	{
		$queueName = sprintf('extensions.%d', $this->getId());
		$db        = $this->getDbo();
		$query     = $db->getQuery(true);
		$query
			->select($query->jsonExtract($db->quoteName('item'), '$.data'))
			->from($db->quoteName('#__queue'))
			->where(
				[
					$query->jsonExtract($db->quoteName('item'), '$.queueType') . ' = ' . $db->quote($queueName),
					$query->jsonExtract($db->quoteName('item'), '$.siteId') . ' = ' . (int) $this->getId(),
				]
			);


		try
		{
			return array_map(
				function ($json) {
					$item = json_decode($json);

					return $item->id ?? null;
				},
				$db->setQuery($query)->loadColumn() ?: []
			);
		}
		catch (Exception $e)
		{
			return [];
		}
	}

	public function getConfig(): Registry
	{
		$config = $this->getFieldValue('config');

		return ($config instanceof Registry) ? $config : (new Registry($config));
	}

	public function saveDownloadKey(int $extensionId, ?string $key): void
	{
		$extensions = (array) $this->getConfig()->get('extensions.list');

		if (!array_key_exists($extensionId, $extensions))
		{
			throw new RuntimeException(
				sprintf('Extension #%d does not exist in site #%d (%s)', $extensionId, $this->getId(), $this->name)
			);
		}

		$extension = $extensions[$extensionId];
		$dlKeyInfo = $extension?->downloadkey;

		if ($dlKeyInfo?->supported !== true || !is_array($dlKeyInfo?->updatesites) || empty($dlKeyInfo?->updatesites))
		{
			throw new RuntimeException(
				sprintf(
					'Extension #%d (%s) in site #%d (%s) does not support Download Keys', $extensionId,
					$extension->description, $this->getId(), $this->name
				)
			);
		}

		// For each update site, save the Download Key
		$updateSites = ArrayHelper::toInteger($dlKeyInfo->updatesites);
		/** @var \GuzzleHttp\Client $httpClient */
		$httpClient = $this->container->httpFactory->makeClient(cache: false);
		$promises   = array_map(
			function (int $id) use ($httpClient, $dlKeyInfo, $key) {
				$uriPath = sprintf('/index.php/v1/panopticon/updatesite/%s', $id);
				[$url, $options] = $this->getRequestOptions($this, $uriPath);

				/**
				 * ⚠️ WARNING! ⚠️
				 * 1. Even though the field is called extra_query we must send the raw key. The server adds the prefix
				 *    and suffix.
				 * 2. We MUST use RequestOptions::JSON instead of RequestOptions::FORM_PARAMS because the data must be
				 *    sent as a JSON document (Content-Type: application/json and the body encoded as JSON). This is
				 *    exactly what RequestOptions::JSON does.
				 * 3. The server returns the raw key, without the prefix and suffix, after saving the Download Key.
				 */
				$options[RequestOptions::JSON] = [
					'extra_query' => $key,
				];

				return $httpClient
					->patchAsync($url, $options)
					->then(
						function (ResponseInterface $response) use ($key) {
							try
							{
								$document = @json_decode($this->sanitizeJson($response->getBody()->getContents()));
							}
							catch (\Exception $e)
							{
								$document = null;
							}

							$errors = $document?->errors ?? [];

							if (is_array($errors) && !empty($errors))
							{
								$message = array_shift($errors);

								throw new RuntimeException($message);
							}

							$data = $document?->data;

							if (empty($data) || empty($data?->attributes))
							{
								throw new RuntimeException(
									sprintf(
										'Could not retrieve information for site #%d (%s). Invalid data returned from API call.',
										$this->getId(), $this->name
									)
								);
							}

							if ($data?->attributes?->extra_query != $key)
							{
								throw new RuntimeException("Could not save the Download Key");
							}

							return true;
						},
						function (RequestException $e) {
							throw new RuntimeException(
								sprintf(
									'Could not save the Download Key for site #%d (%s). The server replied with the following error: %s',
									$this->id, $this->name, $e->getMessage()
								)
							);
						}
					)
					->otherwise(
						function (Throwable $e) {
							return $e;
						}
					);
			}, $updateSites
		);

		$responses = Utils::settle($promises)->wait(true);

		foreach ($responses as $response)
		{
			if (!isset($response['value']))
			{
				continue;
			}

			if ($response['value'] instanceof Throwable)
			{
				throw $response['value'];
			}
		}

		// Reload the update information
		/** @var RefreshSiteInfo $callback */
		$callback = $this->container->taskRegistry->get('refreshinstalledextensions');
		$dummy    = new \stdClass();
		$registry = new Registry();

		$registry->set('limitStart', 0);
		$registry->set('limit', 1);
		$registry->set('force', true);
		$registry->set('forceUpdates', true);
		$registry->set('filter.ids', [$this->getId()]);

		do
		{
			$return = $callback($dummy, $registry);
		} while ($return === Status::WILL_RESUME->value);
	}

	protected function getSiteSpecificTask(string $type): ?Task
	{
		$taskModel = $this->getContainer()->mvcFactory->makeTempModel('Task');
		/** @var DataModel\Collection $taskCollection */
		$taskCollection = $taskModel
			->where('site_id', '=', $this->getId())
			->where('type', '=', $type)
			->get(0, 100);

		if ($taskCollection->isEmpty())
		{
			return null;
		}

		/** @var Task $task */
		$task          = $taskCollection->first();
		$task->storage = $task->storage instanceof Registry ? $task->storage : new Registry($task->storage ?: '{}');

		return $task;
	}

	protected function getSiteSpecificTasks(string $type): DataModel\Collection
	{
		$taskModel = $this->getContainer()->mvcFactory->makeTempModel('Task');
		/** @var DataModel\Collection $taskCollection */
		$taskCollection = $taskModel
			->where('site_id', '=', $this->getId())
			->where('type', '=', $type)
			->get(0, 100);

		return $taskCollection;
	}

	protected function isSiteSpecificTaskStuck(string $type): bool
	{
		$task = $this->getSiteSpecificTask($type);

		if (empty($task))
		{
			return false;
		}

		return !in_array(
			$task->last_exit_code, [
				Status::INITIAL_SCHEDULE->value,
				Status::OK->value,
				Status::RUNNING->value,
				Status::WILL_RESUME->value,
			]
		);
	}

	protected function isSiteSpecificTaskScheduled(string $type): bool
	{
		$task = $this->getSiteSpecificTask($type);

		if (empty($task) || !$task->enabled || empty($task->next_execution))
		{
			return false;
		}

		if (!$task->next_execution instanceof Date)
		{
			try
			{
				$task->next_execution = $this->container->dateFactory($task->next_execution);
			}
			catch (Exception $e)
			{
				return false;
			}
		}

		if ($task->last_exit_code !== Status::INITIAL_SCHEDULE->value
		    && $task->next_execution < ($this->container->dateFactory()))
		{
			return false;
		}

		return in_array(
			$task->last_exit_code, [
				Status::INITIAL_SCHEDULE->value,
				Status::RUNNING->value,
				Status::WILL_RESUME->value,
			]
		);
	}

	protected function isSiteSpecificTaskRunning(string $type): bool
	{
		$task = $this->getSiteSpecificTask($type);

		if (empty($task) || !$task->enabled || empty($task->next_execution))
		{
			return false;
		}

		if (!$task->next_execution instanceof Date)
		{
			try
			{
				$task->next_execution = $this->container->dateFactory($task->next_execution);
			}
			catch (Exception $e)
			{
				return false;
			}
		}

		return in_array(
			$task->last_exit_code, [
				Status::RUNNING->value,
				Status::WILL_RESUME->value,
			]
		);
	}

	protected function onBeforeDelete($id)
	{
		if (empty($id))
		{
			return;
		}

		// Remove all tasks attached to this site
		/** @var Tasks $taskModel */
		$taskModel = $this->getContainer()->mvcFactory->makeTempModel('Tasks');
		$taskModel->setState('site_id', $id);
		$taskModel->get(true)->delete();
	}

	private function cleanUrl(?string $url): string
	{
		$url = trim($url ?? '');

		if (str_ends_with($url, '?/panopticon_api'))
		{
			$url = substr($url, 0, -16) . '/index.php/panopticon_api';

			if (str_ends_with($url, '/index.php/index.php/panopticon_api'))
			{
				$url = substr($url, 0, -35) . '/index.php/panopticon_api';
			}
		}

		$uri = new Uri($url);

		if (!in_array($uri->getScheme(), ['http', 'https']))
		{
			$uri->setScheme('http');
		}

		$uri->setQuery('');
		$uri->setFragment('');
		$path = rtrim($uri->getPath(), '/');

		if (!str_ends_with($path, '/panopticon_api'))
		{
			if (str_ends_with($path, '/api/index.php'))
			{
				$path = substr($path, 0, -10);
			}

			if (str_contains($path, '/api/'))
			{
				$path = substr($path, 0, strrpos($path, '/api/')) . '/api';
			}

			if (!str_ends_with($path, '/api'))
			{
				$path .= '/api';
			}
		}

		$uri->setPath($path);

		return $uri->toString();
	}

	private function updateDebugInfoInSession(
		?ResponseInterface $response = null, ?string $responseBody = null, ?Throwable $e = null,
		string $prefix = 'testconnection.'
	): void
	{
		$session = $this->getContainer()->segment;

		$session->set($prefix . 'http_status', null);
		$session->set($prefix . 'body', null);
		$session->set($prefix . 'headers', null);
		$session->set($prefix . 'exception.type', null);
		$session->set($prefix . 'exception.message', null);
		$session->set($prefix . 'exception.file', null);
		$session->set($prefix . 'exception.line', null);
		$session->set($prefix . 'exception.trace', null);

		if ($e instanceof Throwable)
		{
			$session->set($prefix . 'exception.type', get_class($e));
			$session->set($prefix . 'exception.message', $e->getMessage());
			$session->set($prefix . 'exception.file', $e->getFile());
			$session->set($prefix . 'exception.line', $e->getLine());
			$session->set($prefix . 'exception.trace', $e->getTraceAsString());
		}

		if ($response instanceof ResponseInterface)
		{
			try
			{
				$session->set($prefix . 'http_status', $response->getStatusCode());
			}
			catch (Throwable $e)
			{
				$session->set($prefix . 'http_status', null);
			}

			try
			{
				$session->set($prefix . 'body', $responseBody);
			}
			catch (Throwable $e)
			{
				$session->set($prefix . 'body', null);
			}

			try
			{
				$session->set($prefix . 'headers', $response->getHeaders());
			}
			catch (Throwable $e)
			{
				$session->set('headers', null);
			}
		}
	}
}