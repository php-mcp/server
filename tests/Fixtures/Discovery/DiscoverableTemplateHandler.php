<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Fixtures\Discovery;

use PhpMcp\Server\Attributes\McpResourceTemplate;
use PhpMcp\Server\Attributes\CompletionProvider;
use PhpMcp\Server\Tests\Fixtures\General\CompletionProviderFixture;

class DiscoverableTemplateHandler
{
    /**
     * Retrieves product details based on ID and region.
     * @param string $productId The ID of the product.
     * @param string $region The sales region.
     * @return array Product details.
     */
    #[McpResourceTemplate(
        uriTemplate: "product://{region}/details/{productId}",
        name: "product_details_template",
        mimeType: "application/json"
    )]
    public function getProductDetails(
        string $productId,
        #[CompletionProvider(provider: CompletionProviderFixture::class)]
        string $region
    ): array {
        return [
            "id" => $productId,
            "name" => "Product " . $productId,
            "region" => $region,
            "price" => ($region === "EU" ? "€" : "$") . (hexdec(substr(md5($productId), 0, 4)) / 100)
        ];
    }

    #[McpResourceTemplate(uriTemplate: "file://{path}/{filename}.{extension}")]
    public function getFileContent(string $path, string $filename, string $extension): string
    {
        return "Content of {$path}/{$filename}.{$extension}";
    }
}
