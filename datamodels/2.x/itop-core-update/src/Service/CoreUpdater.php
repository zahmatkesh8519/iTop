<?php
/**
 * iTop
 * @copyright   Copyright (C) 2010,2019 Combodo SARL
 * @license     http://opensource.org/licenses/AGPL-3.0
 *
 */

namespace Combodo\iTop\CoreUpdate\Service;

use Combodo\iTop\FilesInformation\Service\FilesIntegrity;
use DBBackup;
use Dict;
use Exception;
use IssueLog;
use iTopExtension;
use iTopExtensionsMap;
use iTopMutex;
use MetaModel;
use SetupUtils;
use utils;
use ZipArchive;

require_once APPROOT.'setup/applicationinstaller.class.inc.php';
require_once APPROOT.'setup/runtimeenv.class.inc.php';

final class CoreUpdater
{
    const DOWNLOAD_DIR = APPROOT.'data/downloaded-core/';
	const UPDATE_DIR = APPROOT.'data/core-update/';

    /**
     * @param bool $bDoBackup
     *
     * @throws \Exception
     */
	public static function CopyCoreFiles()
	{
		set_time_limit(600);

		// Extract updater file from the new version if available
		if (is_file(APPROOT.'setup/appupgradecopy.php'))
		{
			// Remove previous specific updater
			@unlink(APPROOT.'setup/appupgradecopy.php');
		}
		if (is_file(self::UPDATE_DIR.'web/setup/appupgradecopy.php'))
		{
			IssueLog::Info('itop-core-update: Use updater provided in the archive');
			self::CopyFile(self::UPDATE_DIR.'web/setup/appupgradecopy.php', APPROOT.'setup/appupgradecopy.php');
			@include_once(APPROOT.'setup/appupgradecopy.php');
		}

		try
		{
			if (function_exists('AppUpgradeCopyFiles'))
			{
				// start the update
				set_time_limit(600);
				AppUpgradeCopyFiles(self::UPDATE_DIR.'web/');
			}
			else
			{
				// Local function for older iTop versions
				IssueLog::Info('itop-core-update: Use default updater');
				self::LocalUpdateCoreFiles(self::UPDATE_DIR.'web/');
			}
			IssueLog::Info('itop-core-update: Update done, check files integrity');
			FilesIntegrity::CheckInstallationIntegrity(APPROOT);
			IssueLog::Info('itop-core-update: Files integrity OK');

		}
		catch (Exception $e)
		{
			IssueLog::error($e->getMessage());
			IssueLog::Info('itop-core-update: ended');
			throw $e;
		}
		finally
		{
			self::RRmdir(self::UPDATE_DIR);
		}
	}

	/**
	 * @throws \Exception
	 */
	public static function Compile()
	{
		try
		{
			// Compile code
			IssueLog::Info('itop-core-update: Start compilation');
			IssueLog::Info('itop-core-update: Version Dev');

			$sTargetEnv = 'production';
			$oRuntimeEnv = new RunTimeEnvironmentCoreUpdater($sTargetEnv);
			$oRuntimeEnv->CheckDirectories($sTargetEnv);
			$oRuntimeEnv->CompileFrom('production');
			$oConfig = $oRuntimeEnv->MakeConfigFile($sTargetEnv.' (built on '.date('Y-m-d').')');
			$oConfig->Set('access_mode', ACCESS_FULL);
			$oRuntimeEnv->WriteConfigFileSafe($oConfig);
			$oRuntimeEnv->InitDataModel($oConfig, true);

			$aAvailableModules = $oRuntimeEnv->AnalyzeInstallation($oConfig, $oRuntimeEnv->GetBuildDir());
			$aSelectedModules = array();
			foreach ($aAvailableModules as $sModuleId => $aModule)
			{
				if (($sModuleId == ROOT_MODULE) || ($sModuleId == DATAMODEL_MODULE))
				{
					continue;
				}
				else
				{
					$aSelectedModules[] = $sModuleId;
				}
			}
			$oRuntimeEnv->CallInstallerHandlers($aAvailableModules, $aSelectedModules, 'BeforeDatabaseCreation');
			$oRuntimeEnv->CreateDatabaseStructure($oConfig, 'upgrade');
			$oRuntimeEnv->CallInstallerHandlers($aAvailableModules, $aSelectedModules, 'AfterDatabaseCreation');
			$oRuntimeEnv->UpdatePredefinedObjects();
			$oRuntimeEnv->CallInstallerHandlers($aAvailableModules, $aSelectedModules, 'AfterDatabaseSetup');
			$oRuntimeEnv->LoadData($aAvailableModules, $aSelectedModules, false /* no sample data*/);
			$oRuntimeEnv->CallInstallerHandlers($aAvailableModules, $aSelectedModules, 'AfterDataLoad');
			$sDataModelVersion = $oRuntimeEnv->GetCurrentDataModelVersion();
			$oExtensionsMap = new iTopExtensionsMap();
			// Default choices = as before
			$oExtensionsMap->LoadChoicesFromDatabase($oConfig);
			foreach ($oExtensionsMap->GetAllExtensions() as $oExtension)
			{
				// Plus all "remote" extensions
				if ($oExtension->sSource == iTopExtension::SOURCE_REMOTE)
				{
					$oExtensionsMap->MarkAsChosen($oExtension->sCode);
				}
			}
			$aSelectedExtensionCodes = array();
			foreach ($oExtensionsMap->GetChoices() as $oExtension)
			{
				$aSelectedExtensionCodes[] = $oExtension->sCode;
			}
			$oRuntimeEnv->RecordInstallation($oConfig, $sDataModelVersion, $aSelectedModules,
				$aSelectedExtensionCodes, 'Done by the iTop Core Updater');

			IssueLog::Info('itop-core-update: Compilation done');
		}
		catch (Exception $e)
		{
			IssueLog::error($e->getMessage());
			throw $e;
		}
	}

	/**
	 * @param $sFromDir
	 *
	 * @throws \Exception
	 */
	private static function LocalUpdateCoreFiles($sFromDir)
	{
		self::CopyDir($sFromDir, APPROOT);
	}

	/**
	 * @param $sArchiveFile
	 *
	 * @throws \Exception
	 */
	private static function ExtractUpdateFile($sArchiveFile)
	{
		if (!utils::EndsWith($sArchiveFile, '.zip'))
		{
			throw new Exception(Dict::S('iTopUpdate:Error:BadFileFormat'));
		}

		$oArchive = new ZipArchive();
		$oArchive->open($sArchiveFile);

		self::RRmdir(self::UPDATE_DIR);
		SetupUtils::builddir(self::UPDATE_DIR);
		$oArchive->extractTo(self::UPDATE_DIR);
	}

	/**
	 * @throws \Exception
	 */
	public static function Backup()
	{
        $sBackupName = self::GetBackupName();
        $sBackupFile = self::GetBackupFile();
        if (file_exists($sBackupFile))
        {
            @unlink($sBackupFile);
        }

        self::DoBackup($sBackupName);
	}

	/**
	 * @throws \Exception
	 */
	public static function CreateItopArchive()
    {
    	set_time_limit(0);
	    $sItopArchiveFile = self::GetItopArchiveFile();
        if (file_exists($sItopArchiveFile))
        {
	        @unlink($sItopArchiveFile);
        }

        $sTempFile = sys_get_temp_dir().'/'.basename($sItopArchiveFile);
	    if (file_exists($sTempFile))
	    {
		    @unlink($sTempFile);
	    }

	    $aPathInfo = pathInfo(realpath(APPROOT));
        $sParentPath = $aPathInfo['dirname'];
        $sDirName = $aPathInfo['basename'];

        $oZipArchive = new ZipArchive();
	    $oZipArchive->open($sTempFile, ZIPARCHIVE::CREATE);
        $oZipArchive->addEmptyDir($sDirName);
        self::ZipFolder(realpath(APPROOT), $oZipArchive, strlen("$sParentPath/"));
        $oZipArchive->close();
        
        if (!file_exists($sTempFile))
        {
        	IssueLog::Error("Failed to create itop archive $sTempFile");
        }

	    if (@rename($sTempFile, $sItopArchiveFile))
	    {
		    IssueLog::Info("Archive $sItopArchiveFile Created");
	    }
	    else
	    {
		    IssueLog::Error("Failed to create archive $sItopArchiveFile");
	    }
    }

    /**
     *
     * @param string $sTargetFile
     * @throws Exception
     */
    private static function DoBackup($sTargetFile)
    {
        // Make sure the target directory exists
        $sBackupDir = dirname($sTargetFile);
        SetupUtils::builddir($sBackupDir);

        $oBackup = new DBBackup();
        $oBackup->SetMySQLBinDir(MetaModel::GetConfig()->GetModuleSetting('itop-backup', 'mysql_bindir', ''));

        $oMutex = new iTopMutex('backup.'.utils::GetCurrentEnvironment());
        $oMutex->Lock();
        try
        {
            $oBackup->CreateCompressedBackup($sTargetFile);
            IssueLog::Info('itop-core-update: Backup done: '.$sTargetFile);
        }
        catch (Exception $e)
        {
            $oMutex->Unlock();
            throw $e;
        }
        $oMutex->Unlock();
    }

	/**
	 * @param $sSource
	 * @param $sDest
	 *
	 * @throws \Exception
	 */
	public static function CopyDir($sSource, $sDest)
	{
		if (is_dir($sSource))
		{
			if (!is_dir($sDest))
			{
				@mkdir($sDest, 0755);
			}
			$aFiles = scandir($sSource);
			if(sizeof($aFiles) > 0 )
			{
				foreach($aFiles as $sFile)
				{
					if ($sFile == '.' || $sFile == '..' || $sFile == '.svn' || $sFile == '.git')
					{
						// Skip
						continue;
					}

					if (is_dir($sSource.'/'.$sFile))
					{
						// Recurse
						self::CopyDir($sSource.'/'.$sFile, $sDest.'/'.$sFile);
					}
					else
					{
						if (is_link($sDest.'/'.$sFile))
						{
							unlink($sDest.'/'.$sFile);
						}
						self::CopyFile($sSource.'/'.$sFile, $sDest.'/'.$sFile);
					}
				}
			}
		}
		elseif (is_file($sSource))
		{
			self::CopyFile($sSource, $sDest);
		}
	}

	public static function RRmdir($sDir) {
		if (is_dir($sDir))
		{
			$oDir = @opendir($sDir);
			while (false !== ($sFile = @readdir($oDir)))
			{
				if (($sFile != '.') && ($sFile != '..'))
				{
					$sFull = $sDir.'/'.$sFile;
					if (is_dir($sFull))
					{
						self::RRmdir($sFull);
					}
					else
					{
						@unlink($sFull);
					}
				}
			}
			@closedir($oDir);
			@rmdir($sDir);
		}
	}

	/**
	 * @param $sSource
	 * @param $sDest
	 *
	 * @throws \Exception
	 */
	public static function CopyFile($sSource, $sDest)
	{
		if (is_file($sSource))
		{
			if (!@copy($sSource, $sDest))
			{
				// Try changing the mode of the file
				@chmod($sDest, 0644);
				if (!@copy($sSource, $sDest))
				{
					throw new Exception(Dict::Format('iTopUpdate:Error:Copy', $sSource, $sDest));
				}
			}
		}
	}

	/**
	 * Add files and sub-directories in a folder to zip file.
	 *
	 * @param string $sFolder
	 * @param ZipArchive $oZipArchive
	 * @param int $iStrippedLength Number of text to be removed from the file path.
	 */
	private static function ZipFolder($sFolder, &$oZipArchive, $iStrippedLength) {
		$oFolder = opendir($sFolder);
		while (false !== ($sFile = readdir($oFolder))) {
			if (($sFile == '.') || ($sFile == '..'))
			{
				continue;
			}
			$sFilePath = "$sFolder/$sFile";

			$sLocalItopPath = utils::LocalPath($sFilePath);
			if ($sLocalItopPath == 'data/backups' || $sLocalItopPath == 'log')
			{
				continue;
			}

			// Remove prefix from file path before add to zip.
			$sLocalPath = substr($sFilePath, $iStrippedLength);
			if (is_file($sFilePath)) {
				$oZipArchive->addFile($sFilePath, $sLocalPath);
			} elseif (is_dir($sFilePath)) {
				// Add sub-directory.
				$oZipArchive->addEmptyDir($sLocalPath);
				self::ZipFolder($sFilePath, $oZipArchive, $iStrippedLength);
			}
		}
		closedir($oFolder);
	}

	/**
	 * @return string
	 */
	private static function GetItopArchiveName()
	{
		$sItopArchiveName = APPROOT.'data/backups/itop';
		return $sItopArchiveName;
	}

	/**
	 * @return string
	 */
	public static function GetItopArchiveFile()
	{
		$sItopArchiveFile = self::GetItopArchiveName().'.zip';
		return $sItopArchiveFile;
	}

    /**
     * @return string
     */
    private static function GetBackupName()
    {
        $sBackupName = APPROOT.'data/backups/manual/backup-core-update';
        return $sBackupName;
    }

    /**
     * @return string
     */
    public static function GetBackupFile()
    {
        $sBackupFile = self::GetBackupName().'.tar.gz';
        return $sBackupFile;
    }

    /**
     * @param $sArchiveFile
     *
     * @throws \Exception
     */
    public static function ExtractDownloadedFile($sArchiveFile)
    {
        try
        {
            // Extract archive file
            self::ExtractUpdateFile($sArchiveFile);

            IssueLog::Info('itop-core-update: Archive extracted, check files integrity');

            // Check files integrity
            FilesIntegrity::CheckInstallationIntegrity(self::UPDATE_DIR.'web/');

            IssueLog::Info('itop-core-update: Files integrity OK');
        }
        catch (Exception $e)
        {
            self::RRmdir(self::UPDATE_DIR);
            throw $e;
        }
        finally
        {
	        self::RRmdir(self::DOWNLOAD_DIR);
        }
    }

    /**
     * @return string
     * @throws \Exception
     */
    public static function GetVersionToInstall()
    {
        try
        {
            $sConfigFile = self::UPDATE_DIR.'web/core/config.class.inc.php';
            if (!is_file($sConfigFile))
            {
                throw new Exception(Dict::S(Dict::S('iTopUpdate:Error:BadFileContent')));
            }

            $sContents = file_get_contents($sConfigFile);
            preg_match_all("@define\('(?<name>ITOP_[^']*)', '(?<value>[^']*)'\);@", $sContents, $aMatches);
            if (empty($aMatches))
            {
                throw new Exception(Dict::S(Dict::S('iTopUpdate:Error:BadFileContent')));
            }
            $aValues = array();
            foreach ($aMatches['name'] as $index => $sName)
            {
                $aValues[$sName] = $aMatches['value'][$index];
            }

            if ($aValues['ITOP_APPLICATION'] != ITOP_APPLICATION)
            {
                throw new Exception(Dict::S('iTopUpdate:Error:BadItopProduct'));
            }

	        // Extract updater file from the new version if available
	        if (is_file(APPROOT.'setup/appupgradecheck.php'))
	        {
		        // Remove previous specific updater
		        @unlink(APPROOT.'setup/appupgradecheck.php');
	        }
	        if (is_file(self::UPDATE_DIR.'web/setup/appupgradecheck.php'))
	        {
		        IssueLog::Info('itop-core-update: Use updater provided in the archive');
		        self::CopyFile(self::UPDATE_DIR.'web/setup/appupgradecheck.php', APPROOT.'setup/appupgradecheck.php');
		        @include_once(APPROOT.'setup/appupgradecheck.php');
	        }
	        if (function_exists('AppUpgradeCheckInstall'))
	        {
		        AppUpgradeCheckInstall();
	        }

            return Dict::Format('UI:iTopVersion:Long', $aValues['ITOP_APPLICATION'], $aValues['ITOP_VERSION'], $aValues['ITOP_REVISION'], $aValues['ITOP_BUILD_DATE']);
        }
        catch (Exception $e)
        {
            self::RRmdir(self::UPDATE_DIR);
            self::RRmdir(self::DOWNLOAD_DIR);
            throw $e;
        }
    }
}