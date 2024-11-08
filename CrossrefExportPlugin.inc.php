<?php

import('lib.pkp.classes.plugins.ImportExportPlugin');
import('plugins.importexport.crossref.CrossrefExportDeployment');
define('CROSSREF_API_DEPOSIT_OK', 200);
define('CROSSREF_STATUS_FAILED', 'failed');
define('CROSSREF_API_URL', 'https://api.crossref.org/v2/deposits');
define('CROSSREF_API_URL_DEV', 'https://test.crossref.org/v2/deposits');
define('EXPORT_STATUS_REGISTERED', 'registered');
use APP\facades\Repo;
use PKP\facades\Locale;
use Illuminate\Support\Facades\DB;
use PKP\db\DAORegistry;

class CrossrefExportPlugin extends ImportExportPlugin {

	function __construct() {
		parent::__construct();
	}

	function display($args, $request) {
		$path = array('plugin', $this->getName());
		$templateMgr = TemplateManager::getManager($request);
		parent::display($args, $request);

		$templateMgr->assign('plugin', $this->getName());

		switch (array_shift($args)) {
			case 'settings':
				$this->getSettings($templateMgr);
				$this->updateSettings($request);
				$request->redirect(null, 'management', 'importexport', array('plugin', 'CrossrefExportPlugin'));
			case '':
				$this->getSettings($templateMgr);
				$this->depositHandler($request, $templateMgr);
				$templateMgr->display($this->getTemplateResource('index.tpl'));
				break;
			case 'export':
				import('classes.notification.NotificationManager');
				$result = $this->actionSubmissions($request, 'export');
				$this->createNotifications($request, $result);
				break;
			case 'deposit':
				import('classes.notification.NotificationManager');
				$result = $this->actionSubmissions($request, 'deposit');
				$this->createNotifications($request, $result);
				$request->redirect(null, null, null, $path, null, 'deposited-tab');
				break;
			default:
				$dispatcher = $request->getDispatcher();
				$dispatcher->handle404();
		}
	}

	function getName() {
		return 'CrossrefExportPlugin';
	}

	function getSettings(TemplateManager $templateMgr) {
		$request = Application::get()->getRequest();
		$press = $request->getPress();
		$username = $this->getSetting($press->getId(), 'username');
		$templateMgr->assign('username', $username);
		$password = $this->getSetting($press->getId(), 'password');
		$templateMgr->assign('password', $password);
		$testMode = $this->getSetting($press->getId(), 'testMode');
		$templateMgr->assign('testMode', $testMode);
		return array($press, $username, $password, $testMode);
	}

	function updateSettings($request) {
		$contextId = $request->getContext()->getId();
		$userVars = $request->getUserVars();
		if (count($userVars) > 0) {
			$this->updateSetting($contextId, "username", $userVars["username"]);
			$this->updateSetting($contextId, "password", $userVars["password"]);
			$this->updateSetting($contextId, "testMode", $userVars["testMode"]);
		}
	}

	private function depositHandler($request, TemplateManager $templateMgr) {
		$context = $request->getContext();
		$username = $this->getSetting($context->getId(), 'username');
		$password = $this->getSetting($context->getId(), 'password');

		error_log("Context ID: " . $context->getId());
		error_log("Username: " . $username);
		error_log("Password: " . $password);

		$collector = Repo::submission()->getCollector()
	        ->filterByContextIds([$context->getId()])
	        ->filterByStatus([Submission::STATUS_PUBLISHED]);
			
		// Log the details of the filtering criteria (cannot use toSql() method)
		error_log("Filtering by context ID: " . $context->getId());
		error_log("Filtering by status: " . Submission::STATUS_PUBLISHED);

		$submissions = $collector->getMany();
		
		error_log("Number of submissions found: " . count($submissions));

		$itemsQueue = [];
		$itemsDeposited = [];
		$locale = Locale::getLocale();

		foreach ($submissions as $submission) {
			$submissionId = $submission->getId();
        	error_log("");
        	error_log("Processing submission ID: " . $submissionId);
			
			$publication = $submission->getCurrentPublication();
			$doi = $submission->getCurrentPublication()->getData('doiObject');
			//error_log("DOI: " . var_export($doi, true));

			$registeredDoi = DB::table('submission_settings')
						->where('submission_id', '=', $submissionId)
						->where('setting_name', '=', 'crossref::registeredDoi')
						->value('setting_value');
			//error_log("Registered DOI: " . var_export($registeredDoi, true));

			$failedMsg = DB::table('submission_settings')
						->where('submission_id', '=', $submissionId)
						->where('setting_name', '=', 'crossref::failedMsg')
						->value('setting_value');

			$notices = [];
			$errors = [];

			$chapters = $publication->getData('chapters');
			
			//error_log("chapters: " . var_export($chapters, true));
			$chapterDois = [];
			foreach ($chapters as $chapter) {
				error_log("DOI OBJECT : ".var_export($chapter->getData('doiObject'), true));
				if ($chapter->getData('doiObject')){
					error_log("DOI OBJECT URL : ".var_export($chapter->getData('doiObject')->getData('doi'), true));
					$chapterDois[] = $chapter->getData('doiObject')->getData('doi');
				}
			}

			// Check for notices and errors:
			// Notice: No ISBN! Element 'noisbn' will be used in export.
			// Notice: Crossref failed messages
			// Notice: No ISSN for series!
			// Error: No publisher name in Press settings

			$publicationFormats = $publication->getData('publicationFormats');
			$noIsbn = true;
			foreach ($publicationFormats as $publicationFormat) {
				$identificationCodes = $publicationFormat->getIdentificationCodes();
				while ($identificationCode = $identificationCodes->next()) {
					if ($identificationCode->getCode() == "02" || $identificationCode->getCode() == "15") {
						// 02 and 15: ONIX codes for ISBN-10 or ISBN-13
						$noIsbn = false;
					}
				}
			}
			if ($noIsbn){
				$notices[] = __('plugins.importexport.crossref.notice.noIsbn');
			}

			if ($failedMsg) {
				 $notices[] = $failedMsg;
			}

			if (!$username && !$password) {
				 $errors[] = __('');
			}

			if (!$context->getData('publisher')) {
				 $errors[] = __('plugins.importexport.crossref.error.noPublisher');
			}

			// Remplacez l'utilisation de SeriesDAO par Repo::section()
			$seriesId = $publication->getData('seriesId');
			$series = $seriesId ? Repo::section()->get($seriesId) : null;

			if ($series) {
				if (!$series->getOnlineISSN() && !$series->getPrintISSN()) {
					$notices[] = __('plugins.importexport.crossref.error.noIssn');
				}
			}

			$userGroups = Repo::userGroup()->getCollector()
            ->filterByContextIds([$submission->getData('contextId')])
            ->getMany();


			$doi = $publication->getData('doiObject');
			if ($doi instanceof \PKP\doi\Doi) {
				$doiString = $doi->getDoi(); // ou toute autre méthode qui retourne le DOI comme chaîne
			} else {
				$doiString = ''; // ou une valeur par défaut
			}

			if ($doiString and $registeredDoi) {
				$itemsDeposited[] = array(
					'id' => $submissionId,
					'title' => $submission->getLocalizedTitle($locale),
					'authors' => $publication->getAuthorString($userGroups),
					'pubId' => $doiString,
					'chapterPubIds' => $chapterDois,
					'notices' => $notices,
					'errors' => $errors,
				);
				error_log("Added to itemsDeposited: " . $submissionId);
			}
			if ($doi and !$registeredDoi) {
				$itemsQueue[] = array(
					'id' => $submissionId,
					'title' => $submission->getLocalizedTitle($locale),
					'authors' => $publication->getAuthorString($userGroups),
					'pubId' => $doiString,
					'chapterPubIds' => $chapterDois,
					'notices' => $notices,
					'errors' => $errors,
				);
			}
		}

		$templateMgr->assign('itemsQueue', $itemsQueue);
		$templateMgr->assign('itemsSizeQueue', sizeof($itemsQueue));
		$templateMgr->assign('itemsDeposited', $itemsDeposited);
		$templateMgr->assign('itemsSizeDeposited', sizeof($itemsDeposited));
	}

	function isTestMode($context) {
		return ($this->getSetting($context->getId(), 'testMode') == 1);
	}

	function actionSubmissions($request, $action) {
		$submissionIds = (array)$request->getUserVar('submission');
		import('lib.pkp.classes.file.FileManager');
		$fileManager = new FileManager();
		$result = array();
		$press = $request->getPress();
		foreach ($submissionIds as $submissionId) {
			$deployment = new CrossrefExportDeployment($request, $this);
			$submission = Repo::submission()->get($submissionId);
			$publication = $submission->getCurrentPublication();
			$doi = $publication->getStoredPubId('doi');
			if ($doi) {
				$DOMDocument = new DOMDocument('1.0', 'utf-8');
				$DOMDocument->formatOutput = true;
				$DOMDocument = $deployment->createNodes($DOMDocument, $submission, $publication);
				$exportFileName = $this->getExportFileName($this->getExportPath(), 'crossref-' . $submissionId, $press, '.xml');
				$exportXml = $DOMDocument->saveXML();
				$fileManager->writeFile($exportFileName, $exportXml);
				switch ($action) {
					case 'export':
						$fileManager->downloadByPath($exportFileName);
						break;
					case 'deposit':
						$result = $this->depositXML($submission, $press, $exportFileName);
						break;
				}
				$fileManager->deleteByPath($exportFileName);
			}
		}
		return $result;
	}

	function depositXML($submission, $context, $filename) {
		$status = null;
		$msgSave = null;
		$httpClient = Application::get()->getHttpClient();
		assert(is_readable($filename));
		try {
			$response = $httpClient->request('POST',
				$this->isTestMode($context) ? CROSSREF_API_URL_DEV : CROSSREF_API_URL,
				[
					'multipart' => [
						[
							'name'     => 'usr',
							'contents' => $this->getSetting($context->getId(), 'username'),
						],
						[
							'name'     => 'pwd',
							'contents' => $this->getSetting($context->getId(), 'password'),
						],
						[
							'name'     => 'operation',
							'contents' => 'doMDUpload',
						],
						[
							'name'     => 'mdFile',
							'contents' => fopen($filename, 'r'),
						],
					]
				]
			);
		} catch (GuzzleHttp\Exception\RequestException $e) {
			$returnMessage = $e->getMessage();
			if ($e->hasResponse()) {
				$eResponseBody = $e->getResponse()->getBody(true);
				$eStatusCode = $e->getResponse()->getStatusCode();
				if ($eStatusCode == CROSSREF_API_DEPOSIT_ERROR_FROM_CROSSREF) {
					$xmlDoc = new DOMDocument();
					$xmlDoc->loadXML($eResponseBody);
					$batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);
					$msg = $xmlDoc->getElementsByTagName('msg')->item(0)->nodeValue;
					$msgSave = $msg . PHP_EOL . $eResponseBody;
					$status = CROSSREF_STATUS_FAILED;
					$this->updateDepositStatus($context, $submission, $status, $batchIdNode->nodeValue, $msgSave);
					$returnMessage = $msg . ' (' .$eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
				} else {
					$returnMessage = $eResponseBody . ' (' .$eStatusCode . ' ' . $e->getResponse()->getReasonPhrase() . ')';
				}
			}
			return [['plugins.importexport.crossref.register.error.mdsError'], [$returnMessage]];
		}

		// Get DOMDocument from the response XML string
		$xmlDoc = new DOMDocument();
		$xmlDoc->loadXML($response->getBody());
		$batchIdNode = $xmlDoc->getElementsByTagName('batch_id')->item(0);

		// Get the DOI deposit status
		// If the deposit failed
		$failureCountNode = $xmlDoc->getElementsByTagName('failure_count')->item(0);
		$failureCount = (int) $failureCountNode->nodeValue;
		
		if ($failureCount > 0) {
			$status = CROSSREF_STATUS_FAILED;
			$result = false;
		} else {
			// Deposit was received
			$status = EXPORT_STATUS_REGISTERED;
			$result = true;

			// If there were some warnings, display them
			$warningCountNode = $xmlDoc->getElementsByTagName('warning_count')->item(0);
			$warningCount = (int) $warningCountNode->nodeValue;
			if ($warningCount > 0) {
				$result = array(array('plugins.importexport.crossref.register.success.warning', htmlspecialchars($response->getBody())));
			}
			// A possibility for other plugins (e.g. reference linking) to work with the response
			HookRegistry::call('crossrefexportplugin::deposited', array($this, $response->getBody(), $submission));
		}

		// Update the status
		if ($status) {
			$this->updateDepositStatus($context, $submission, $status, $batchIdNode->nodeValue, $msgSave);
			// $this->updateObject($submission);
		}

		return $result;
	}

	function updateDepositStatus($context, $submission, $status, $batchId, $failedMsg = null) {
		assert(is_a($submission, 'Submission'));

		DB::table('submission_settings')
			->where('submission_id', '=', $submission->getId())
			->where('setting_name', '=', 'crossref::failedMsg')
			->delete();

		if ($failedMsg) {
			DB::table('submission_settings')->insert([
				'submission_id' => $submission->getId(),
				'locale' => '',
				'setting_name' => 'crossref::failedMsg',
				'setting_value' => $failedMsg
			]);
		}

		if ($status == EXPORT_STATUS_REGISTERED) {
			// Save the DOI -- the submission will be updated
			$this->saveRegisteredDoi($context, $submission);
		}
	}

	function saveRegisteredDoi($context, $submission) {
		$registeredDoi = $submission->getStoredPubId('doi');
		assert(!empty($registeredDoi));

		DB::table('submission_settings')
			->where('submission_id', '=', $submission->getId())
			->where('setting_name', '=', 'crossref::registeredDoi')
			->delete();

		DB::table('submission_settings')->insert([
			'submission_id' => $submission->getId(),
			'locale' => '',
			'setting_name' => 'crossref::registeredDoi',
			'setting_value' => $registeredDoi
		]);
	}

	private function createNotifications($request, $result) {
		if (!$result) {
			$this->_sendNotification(
				$request->getUser(),
				'plugins.importexport.crossref.register.error.mdsError',
				NOTIFICATION_TYPE_ERROR
			);
		} else {
			if (!is_array($result)) {
				$this->_sendNotification(
					$request->getUser(),
					'plugins.importexport.crossref.register.success',
					NOTIFICATION_TYPE_SUCCESS
				);
			} else {
				foreach ($result as $submission => $error) {
					assert(is_array($error) && count($error) >= 1);
					self::writeLog($submission . " ::  " . $error, 'ERROR');
					$this->_sendNotification(
						$request->getUser(),
						$error[0],
						NOTIFICATION_TYPE_ERROR,
						(isset($error[1]) ? $error[1] : null)
					);
				}
			}
		}

	}

	private static function writeLog($message, $level) {
		$fineStamp = date('Y-m-d H:i:s') . substr(microtime(), 1, 4);
		error_log("$fineStamp $level $message\n", 3, self::logFilePath());
	}

	function _sendNotification($user, $message, $notificationType, $param = null) {
		static $notificationManager = null;
		if (is_null($notificationManager)) {
			import('classes.notification.NotificationManager');
			$notificationManager = new NotificationManager();
		}
		if (!is_null($param)) {
			$params = array('param' => $param);
		} else {
			$params = null;
		}
		$params = $param ? array('param' => $param) : array();
		$notificationManager->createTrivialNotification(
			$user->getId(),
			$notificationType,
			array('contents' => __($message, $params))
		);
	}

	public static function logFilePath() {
		return Config::getVar('files', 'files_dir') . '/CROSSREF_ERROR.log';
	}


	function executeCLI($scriptName, &$args) {
		fatalError('Not implemented.');
	}

	function getDescription() {
		return __('plugins.importexport.crossref.description');
	}

	function getDisplayName() {
		return __('plugins.importexport.crossref.displayName');
	}

	function getPluginSettingsPrefix() {
		return 'crossref';
	}

	function register($category, $path, $mainContextId = null) {
		$success = parent::register($category, $path, $mainContextId);
		if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) return $success;
		if ($success && $this->getEnabled()) {
			$this->addLocaleData();
			$this->import('CrossrefExportDeployment');
		}

		return $success;
	}

	function usage($scriptName) {
		fatalError('Not implemented.');
	}

}
