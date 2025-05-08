<?php

namespace PhpMcp\Server\Support;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlockFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Parses DocBlocks using phpdocumentor/reflection-docblock.
 */
class DocBlockParser
{
    private DocBlockFactory $docBlockFactory;

    public function __construct(private LoggerInterface $logger)
    {
        $this->docBlockFactory = DocBlockFactory::createInstance();
    }

    /**
     * Safely parses a DocComment string into a DocBlock object.
     */
    public function parseDocBlock(?string $docComment): ?DocBlock
    {
        if (empty($docComment)) {
            return null;
        }
        try {
            return $this->docBlockFactory->create($docComment);
        } catch (Throwable $e) {
            // Log error or handle gracefully if invalid DocBlock syntax is encountered
            $this->logger->warning('Failed to parse DocBlock', [
                'error' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Gets the summary line from a DocBlock.
     */
    public function getSummary(?DocBlock $docBlock): ?string
    {
        if (! $docBlock) {
            return null;
        }
        $summary = trim($docBlock->getSummary());

        return $summary ?: null; // Return null if empty after trimming
    }

    /**
     * Gets the description from a DocBlock (summary + description body).
     */
    public function getDescription(?DocBlock $docBlock): ?string
    {
        if (! $docBlock) {
            return null;
        }
        $summary = trim($docBlock->getSummary());
        $descriptionBody = trim((string) $docBlock->getDescription());

        if ($summary && $descriptionBody) {
            return $summary."\n\n".$descriptionBody;
        }
        if ($summary) {
            return $summary;
        }
        if ($descriptionBody) {
            return $descriptionBody;
        }

        return null;
    }

    /**
     * Extracts @param tag information from a DocBlock, keyed by variable name (e.g., '$paramName').
     *
     * @return array<string, Param>
     */
    public function getParamTags(?DocBlock $docBlock): array
    {
        if (! $docBlock) {
            return [];
        }

        /** @var array<string, Param> $paramTags */
        $paramTags = [];
        foreach ($docBlock->getTagsByName('param') as $tag) {
            if ($tag instanceof Param && $tag->getVariableName()) {
                $paramTags['$'.$tag->getVariableName()] = $tag;
            }
        }

        return $paramTags;
    }

    /**
     * Gets the @return tag information from a DocBlock.
     */
    public function getReturnTag(?DocBlock $docBlock): ?Return_
    {
        if (! $docBlock) {
            return null;
        }
        /** @var Return_|null $returnTag */
        $returnTag = $docBlock->getTagsByName('return')[0] ?? null;

        return $returnTag;
    }

    /**
     * Gets the description string from a Param tag.
     */
    public function getParamDescription(?Param $paramTag): ?string
    {
        return $paramTag ? (trim((string) $paramTag->getDescription()) ?: null) : null;
    }

    /**
     * Gets the type string from a Param tag.
     */
    public function getParamTypeString(?Param $paramTag): ?string
    {
        if ($paramTag && $paramTag->getType()) {
            $typeFromTag = trim((string) $paramTag->getType());
            if (! empty($typeFromTag)) {
                return ltrim($typeFromTag, '\\');
            }
        }

        return null;
    }

    /**
     * Gets the description string from a Return_ tag.
     */
    public function getReturnDescription(?Return_ $returnTag): ?string
    {
        return $returnTag ? (trim((string) $returnTag->getDescription()) ?: null) : null;
    }

    /**
     * Gets the type string from a Return_ tag.
     */
    public function getReturnTypeString(?Return_ $returnTag): ?string
    {
        if ($returnTag && $returnTag->getType()) {
            $typeFromTag = trim((string) $returnTag->getType());
            if (! empty($typeFromTag)) {
                return ltrim($typeFromTag, '\\');
            }
        }

        return null;
    }
}
