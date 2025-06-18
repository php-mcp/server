<?php

namespace PhpMcp\Server\Tests\Unit\Utils;

use phpDocumentor\Reflection\DocBlock;
use phpDocumentor\Reflection\DocBlock\Tags\Deprecated;
use phpDocumentor\Reflection\DocBlock\Tags\Param;
use phpDocumentor\Reflection\DocBlock\Tags\Return_;
use phpDocumentor\Reflection\DocBlock\Tags\See;
use phpDocumentor\Reflection\DocBlock\Tags\Throws;
use PhpMcp\Server\Utils\DocBlockParser;
use PhpMcp\Server\Tests\Fixtures\General\DocBlockTestFixture;
use ReflectionMethod;

beforeEach(function () {
    $this->parser = new DocBlockParser();
});

test('getSummary returns correct summary', function () {
    $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryOnly');
    $docComment = $method->getDocComment() ?: null;
    $docBlock = $this->parser->parseDocBlock($docComment);
    expect($this->parser->getSummary($docBlock))->toBe('Simple summary line.');

    $method2 = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryAndDescription');
    $docComment2 = $method2->getDocComment() ?: null;
    $docBlock2 = $this->parser->parseDocBlock($docComment2);
    expect($this->parser->getSummary($docBlock2))->toBe('Summary line here.');
});

test('getDescription returns correct description', function () {
    $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryAndDescription');
    $docComment = $method->getDocComment() ?: null;
    $docBlock = $this->parser->parseDocBlock($docComment);
    $expectedDesc = "Summary line here.\n\nThis is a longer description spanning\nmultiple lines.\nIt might contain *markdown* or `code`.";
    expect($this->parser->getDescription($docBlock))->toBe($expectedDesc);

    $method2 = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryOnly');
    $docComment2 = $method2->getDocComment() ?: null;
    $docBlock2 = $this->parser->parseDocBlock($docComment2);
    expect($this->parser->getDescription($docBlock2))->toBe('Simple summary line.');
});

test('getParamTags returns structured param info', function () {
    $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithParams');
    $docComment = $method->getDocComment() ?: null;
    $docBlock = $this->parser->parseDocBlock($docComment);
    $params = $this->parser->getParamTags($docBlock);

    expect($params)->toBeArray()->toHaveCount(6);
    expect($params)->toHaveKeys(['$param1', '$param2', '$param3', '$param4', '$param5', '$param6']);

    expect($params['$param1'])->toBeInstanceOf(Param::class);
    expect($params['$param1']->getVariableName())->toBe('param1');
    expect($this->parser->getParamTypeString($params['$param1']))->toBe('string');
    expect($this->parser->getParamDescription($params['$param1']))->toBe('Description for string param.');

    expect($params['$param2'])->toBeInstanceOf(Param::class);
    expect($params['$param2']->getVariableName())->toBe('param2');
    expect($this->parser->getParamTypeString($params['$param2']))->toBe('int|null');
    expect($this->parser->getParamDescription($params['$param2']))->toBe('Description for nullable int param.');

    expect($params['$param3'])->toBeInstanceOf(Param::class);
    expect($params['$param3']->getVariableName())->toBe('param3');
    expect($this->parser->getParamTypeString($params['$param3']))->toBe('bool');
    expect($this->parser->getParamDescription($params['$param3']))->toBeNull();

    expect($params['$param4'])->toBeInstanceOf(Param::class);
    expect($params['$param4']->getVariableName())->toBe('param4');
    expect($this->parser->getParamTypeString($params['$param4']))->toBe('mixed');
    expect($this->parser->getParamDescription($params['$param4']))->toBe('Missing type.');

    expect($params['$param5'])->toBeInstanceOf(Param::class);
    expect($params['$param5']->getVariableName())->toBe('param5');
    expect($this->parser->getParamTypeString($params['$param5']))->toBe('array<string,mixed>');
    expect($this->parser->getParamDescription($params['$param5']))->toBe('Array description.');

    expect($params['$param6'])->toBeInstanceOf(Param::class);
    expect($params['$param6']->getVariableName())->toBe('param6');
    expect($this->parser->getParamTypeString($params['$param6']))->toBe('stdClass');
    expect($this->parser->getParamDescription($params['$param6']))->toBe('Object param.');
});

test('getReturnTag returns structured return info', function () {
    $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithReturn');
    $docComment = $method->getDocComment() ?: null;
    $docBlock = $this->parser->parseDocBlock($docComment);
    $returnTag = $this->parser->getReturnTag($docBlock);

    expect($returnTag)->toBeInstanceOf(Return_::class);
    expect($this->parser->getReturnTypeString($returnTag))->toBe('string');
    expect($this->parser->getReturnDescription($returnTag))->toBe('The result of the operation.');

    $method2 = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithSummaryOnly');
    $docComment2 = $method2->getDocComment() ?: null;
    $docBlock2 = $this->parser->parseDocBlock($docComment2);
    expect($this->parser->getReturnTag($docBlock2))->toBeNull();
});

test('getTagsByName returns specific tags', function () {
    $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithMultipleTags');
    $docComment = $method->getDocComment() ?: null;
    $docBlock = $this->parser->parseDocBlock($docComment);

    expect($docBlock)->toBeInstanceOf(DocBlock::class);

    $throwsTags = $docBlock->getTagsByName('throws');
    expect($throwsTags)->toBeArray()->toHaveCount(1);
    expect($throwsTags[0])->toBeInstanceOf(Throws::class);
    expect((string) $throwsTags[0]->getType())->toBe('\\RuntimeException');
    expect($throwsTags[0]->getDescription()->render())->toBe('If processing fails.');

    $deprecatedTags = $docBlock->getTagsByName('deprecated');
    expect($deprecatedTags)->toBeArray()->toHaveCount(1);
    expect($deprecatedTags[0])->toBeInstanceOf(Deprecated::class);
    expect($deprecatedTags[0]->getDescription()->render())->toBe('Use newMethod() instead.');

    $seeTags = $docBlock->getTagsByName('see');
    expect($seeTags)->toBeArray()->toHaveCount(1);
    expect($seeTags[0])->toBeInstanceOf(See::class);
    expect((string) $seeTags[0]->getReference())->toContain('DocBlockTestFixture::newMethod()');

    $nonExistentTags = $docBlock->getTagsByName('nosuchtag');
    expect($nonExistentTags)->toBeArray()->toBeEmpty();
});

test('handles method with no docblock gracefully', function () {
    $method = new ReflectionMethod(DocBlockTestFixture::class, 'methodWithNoDocBlock');
    $docComment = $method->getDocComment() ?: null;
    $docBlock = $this->parser->parseDocBlock($docComment);

    expect($docBlock)->toBeNull();

    expect($this->parser->getSummary($docBlock))->toBeNull();
    expect($this->parser->getDescription($docBlock))->toBeNull();
    expect($this->parser->getParamTags($docBlock))->toBeArray()->toBeEmpty();
    expect($this->parser->getReturnTag($docBlock))->toBeNull();
});
