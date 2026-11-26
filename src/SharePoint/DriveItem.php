<?php

declare(strict_types=1);

namespace Adx\MarketingMcp\SharePoint;

/**
 * The subset of a Graph driveItem the connector exposes. Deliberately excludes
 * '@microsoft.graph.downloadUrl' (a pre-authenticated URL) and any user emails/IDs.
 */
final class DriveItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly bool $isFolder,
        public readonly string $path,
        public readonly ?string $parentId,
        public readonly int $size = 0,
        public readonly ?string $mimeType = null,
        public readonly ?string $webUrl = null,
        public readonly ?string $lastModified = null,
        public readonly ?string $lastModifiedBy = null,
        public readonly ?int $childCount = null,
    ) {
    }

    /**
     * @param array<string, mixed> $item Graph driveItem JSON
     */
    public static function fromGraph(array $item, string $path, ?string $parentId = null): self
    {
        $isFolder = isset($item['folder']);

        return new self(
            id: (string) ($item['id'] ?? ''),
            name: (string) ($item['name'] ?? ''),
            isFolder: $isFolder,
            path: $path,
            parentId: $parentId ?? (isset($item['parentReference']['id']) ? (string) $item['parentReference']['id'] : null),
            size: (int) ($item['size'] ?? 0),
            mimeType: isset($item['file']['mimeType']) ? (string) $item['file']['mimeType'] : null,
            webUrl: isset($item['webUrl']) ? (string) $item['webUrl'] : null,
            lastModified: isset($item['lastModifiedDateTime']) ? (string) $item['lastModifiedDateTime'] : null,
            lastModifiedBy: isset($item['lastModifiedBy']['user']['displayName']) ? (string) $item['lastModifiedBy']['user']['displayName'] : null,
            childCount: $isFolder && isset($item['folder']['childCount']) ? (int) $item['folder']['childCount'] : null,
        );
    }

    public function extension(): string
    {
        $dot = strrpos($this->name, '.');

        return false === $dot ? '' : strtolower(substr($this->name, $dot + 1));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->isFolder ? 'folder' : 'file',
            'path' => $this->path,
            'size' => $this->size,
            'mimeType' => $this->mimeType,
            'webUrl' => $this->webUrl,
            'lastModified' => $this->lastModified,
            'lastModifiedBy' => $this->lastModifiedBy,
            'childCount' => $this->childCount,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            name: (string) $data['name'],
            isFolder: 'folder' === ($data['type'] ?? 'file'),
            path: (string) ($data['path'] ?? ''),
            parentId: isset($data['parentId']) ? (string) $data['parentId'] : null,
            size: (int) ($data['size'] ?? 0),
            mimeType: $data['mimeType'] ?? null,
            webUrl: $data['webUrl'] ?? null,
            lastModified: $data['lastModified'] ?? null,
            lastModifiedBy: $data['lastModifiedBy'] ?? null,
            childCount: isset($data['childCount']) ? (int) $data['childCount'] : null,
        );
    }
}
