<?php

declare(strict_types=1);


namespace Pixelant\Interest\DataHandling\Operation\Event\Handler;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Pixelant\Interest\Configuration\ConfigurationProvider;
use Pixelant\Interest\DataHandling\Operation\CreateRecordOperation;
use Pixelant\Interest\DataHandling\Operation\DeleteRecordOperation;
use Pixelant\Interest\DataHandling\Operation\Event\BeforeRecordOperationEvent;
use Pixelant\Interest\DataHandling\Operation\Event\BeforeRecordOperationEventHandlerInterface;
use Pixelant\Interest\DataHandling\Operation\Exception\IdentityConflictException;
use Pixelant\Interest\DataHandling\Operation\Exception\MissingArgumentException;
use Pixelant\Interest\DataHandling\Operation\Exception\NotFoundException;
use Pixelant\Interest\Domain\Repository\RemoteIdMappingRepository;
use Pixelant\Interest\Utility\CompatibilityUtility;
use TYPO3\CMS\Core\Resource\AbstractFile;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\OnlineMedia\Helpers\OnlineMediaHelperRegistry;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Intercepts a sys_file request to store the file data in the filesystem.
 */
class PersistFileDataEventHandler implements BeforeRecordOperationEventHandlerInterface
{
    protected RemoteIdMappingRepository $mappingRepository;

    protected ResourceFactory $resourceFactory;

    protected BeforeRecordOperationEvent $event;

    /**
     * @inheritDoc
     */
    public function __invoke(BeforeRecordOperationEvent $event): void
    {
        if ($event->getRecordOperation() instanceof DeleteRecordOperation) {
            return;
        }

        $this->event = $event;

        $isCreateOperation = get_class($this->event->getRecordOperation()) === CreateRecordOperation::class;

        if ($this->event->getRecordOperation()->getTable() !== 'sys_file') {
            return;
        }

        $data = $this->event->getRecordOperation()->getData();

        $fileBaseName = $data['name'];

        if (!CompatibilityUtility::getFileNameValidator()->isValid($fileBaseName)) {
            throw new InvalidFileNameException(
                'Invalid file name: "' . $fileBaseName . '"',
                1634664683340
            );
        }

        $settings = GeneralUtility::makeInstance(ConfigurationProvider::class)->getSettings();

        $storagePath = $this->event->getRecordOperation()->getContentObjectRenderer()->stdWrap(
            $settings['persistence.']['fileUploadFolderPath'],
            $settings['persistence.']['fileUploadFolderPath.'] ?? []
        );

        $this->resourceFactory = GeneralUtility::makeInstance(ResourceFactory::class);

        $storage = $this->resourceFactory->getStorageObjectFromCombinedIdentifier($storagePath);

        try {
            $downloadFolder = $this->resourceFactory->getFolderObjectFromCombinedIdentifier($storagePath);
        } catch (FolderDoesNotExistException $exception) {
            [, $folderPath] = explode(':', $storagePath);

            $downloadFolder = $storage->createFolder($folderPath);
        }

        $hashedSubfolders = (int)$this->event->getRecordOperation()->getContentObjectRenderer()->stdWrap(
            $settings['persistence.']['hashedSubfolders'],
            $settings['persistence.']['hashedSubfolders.'] ?? []
        );

        if ($hashedSubfolders > 0) {
            $fileNameHash = md5($fileBaseName);

            for ($i = 0; $i < $hashedSubfolders; $i++) {
                $subfolderName = substr($fileNameHash, $i, 1);

                if ($downloadFolder->hasFolder($subfolderName)) {
                    $downloadFolder = $downloadFolder->getSubfolder($subfolderName);

                    continue;
                }

                $downloadFolder = $downloadFolder->createFolder($subfolderName);
            }
        }

        if ($isCreateOperation) {
            if ($storage->hasFileInFolder($fileBaseName, $downloadFolder)) {
                throw new IdentityConflictException(
                    'File "' . $fileBaseName . '" already exists in "' . $storagePath . '".',
                    1634666560886
                );
            }
        }

        $this->mappingRepository = GeneralUtility::makeInstance(RemoteIdMappingRepository::class);

        if (!empty($data['fileData'])) {
            $fileContent = $this->handleBase64Input($data['fileData']);
        } else {
            if (empty($data['url']) && $isCreateOperation) {
                throw new MissingArgumentException(
                    'Cannot download file. Missing property "url" in the data.',
                    1634667221986
                );
            } elseif (!empty($data['url'])) {
                if ($isCreateOperation) {
                    $onlineMediaHelperRegistry = GeneralUtility::makeInstance(OnlineMediaHelperRegistry::class);

                    $file = $onlineMediaHelperRegistry->transformUrlToFile(
                        $data['url'],
                        $downloadFolder,
                        $onlineMediaHelperRegistry->getSupportedFileExtensions()
                    );

                    if ($file !== null) {
                        $file->rename(pathinfo($fileBaseName, PATHINFO_FILENAME) . '.' . $file->getExtension());
                    }
                }

                if ($file === null) {
                    $fileContent = $this->handleUrlInput($data['url']);
                }
            }
        }

        if ($file === null) {
            $file = $this->createFileObject($downloadFolder, $fileBaseName, $isCreateOperation);
        }

        if (!empty($fileContent)) {
            $file->setContents($fileContent);
        }

        unset($data['fileData']);
        unset($data['url']);
        unset($data['name']);

        $this->event->getRecordOperation()->setUid($file->getUid());

        $this->event->getRecordOperation()->setData($data);
    }

    /**
     * Creates the file object in FAL.
     *
     * @param Folder $downloadFolder
     * @param string $fileBaseName
     * @param bool $isCreateOperation
     * @return File
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
     */
    protected function createFileObject(
        Folder $downloadFolder,
        string $fileBaseName,
        bool $isCreateOperation
    ): File
    {
        if ($isCreateOperation) {
            return $downloadFolder->createFile($fileBaseName);
        }

        try {
            $file = $this->resourceFactory->getFileObject(
                $this->mappingRepository->get($this->event->getRecordOperation()->getRemoteId())
            );
        } catch (FileDoesNotExistException $exception) {
            if ($this->mappingRepository->get($this->event->getRecordOperation()->getRemoteId()) === 0) {
                throw new NotFoundException(
                    'The file with remote ID "' . $this->event->getRecordOperation()->getRemoteId() . '" does not '
                    . 'exist in this TYPO3 instance.',
                    1634668710602
                );
            }

            throw new NotFoundException(
                'The file with remote ID "' . $this->event->getRecordOperation()->getRemoteId() . '" and UID '
                . '"' . $this->mappingRepository->get($this->event->getRecordOperation()->getRemoteId()) . '" does not exist.',
                1634668857809
            );
        }

        $this->renameFile($file, $fileBaseName);

        return $file;
    }

    /**
     * Decode base64-encoded file data.
     *
     * @param string $fileData
     * @return false|string
     */
    protected function handleBase64Input(string $fileData): string
    {
        $stream = fopen('php://temp', 'rw');

        stream_filter_append($stream, 'convert.base64-decode', STREAM_FILTER_WRITE);

        $length = fwrite($stream, $fileData);

        rewind($stream);

        $fileContent = fread($stream, $length);

        fclose($stream);

        return $fileContent;
    }

    /**
     * Handle file data download from a URL.
     *
     * @param string $url
     * @return string|null
     */
    protected function handleUrlInput(string $url): ?string
    {
        /** @var Client $httpClient */
        $httpClient = GeneralUtility::makeInstance(Client::class);

        $metaData = $this->mappingRepository->getMetaDataValue(
            $this->event->getRecordOperation()->getRemoteId(),
            self::class
        ) ?? [];

        $headers = [];

        if (!empty($metaData['date'])) {
            $headers['If-Modified-Since'] = $metaData['date'];
        }

        if (!empty($metaData['etag'])) {
            $headers['If-None-Match'] = $metaData['etag'];
        }

        try {
            $response = $httpClient->get($url, ['headers' => $headers]);
        } catch (ClientException $exception) {
            if ($exception->getCode() >= 400) {
                throw new NotFoundException(
                    'Request failed. URL: "' . $url . '" Message: "' . $exception->getMessage() . '"',
                    1634667759711
                );
            }
        }

        if ($response->getStatusCode() === 304) {
            return null;
        }

        $this->mappingRepository->setMetaDataValue(
            $this->event->getRecordOperation()->getRemoteId(),
            self::class,
            [
                'date' => $response->getHeader('Date'),
                'etag' => $response->getHeader('ETag'),
            ]
        );

        return $response->getBody()->getContents();
    }

    /**
     * Rename a file if the file name has changed.
     *
     * @param File $file
     * @param string $fileName
     * @throws \TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException
     */
    protected function renameFile(File $file, string $fileName)
    {
        if ($file->getStorage()->sanitizeFileName($fileName) !== $file->getName()) {
            $file->rename($fileName);
        }
    }
}
