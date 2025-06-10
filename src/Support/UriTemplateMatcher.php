<?php

namespace PhpMcp\Server\Support;

// Placeholder - Needs implementation for basic RFC 6570 matching
class UriTemplateMatcher
{
    private string $template;
    private string $regex;
    private array $variableNames = [];

    public function __construct(string $template)
    {
        $this->template = $template;
        $this->compileTemplate();
    }

    private function compileTemplate(): void
    {
        $this->variableNames = [];
        $regexParts = [];

        // Split the template by placeholders, keeping the delimiters
        $segments = preg_split('/(\{\w+\})/', $this->template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        foreach ($segments as $segment) {
            if (preg_match('/^\{(\w+)\}$/', $segment, $matches)) {
                // This segment is a placeholder like {var}
                $varName = $matches[1];
                $this->variableNames[] = $varName;
                // Append named capture group (match non-slash characters)
                $regexParts[] = '(?P<' . $varName . '>[^/]+)';
            } else {
                // This is a literal part, escape it
                $regexParts[] = preg_quote($segment, '#');
            }
        }

        $this->regex = '#^' . implode('', $regexParts) . '$#';
    }

    public function getVariables(): array
    {
        return $this->variableNames;
    }

    public function match(string $uri): ?array
    {
        if (preg_match($this->regex, $uri, $matches)) {
            $variables = [];
            // Extract only the named capture groups
            foreach ($this->variableNames as $varName) {
                if (isset($matches[$varName])) {
                    $variables[$varName] = $matches[$varName];
                }
            }
            return $variables;
        }

        return null;
    }
}
