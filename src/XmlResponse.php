<?php

declare(strict_types=1);

namespace VolunteerLine;

final class XmlResponse
{
    /** @var array<int, string> */
    private array $children = [];

    private string $defaultVoice;
    private string $defaultLanguage;

    public function __construct(string $defaultVoice = 'male', string $defaultLanguage = 'en-US')
    {
        $this->defaultVoice = $defaultVoice;
        $this->defaultLanguage = $defaultLanguage;
    }

    public function say(string $text, ?string $action = null, ?string $voice = null, ?string $language = null): self
    {
        $attrs = [
            'voice' => $voice ?? $this->defaultVoice,
            'language' => $language ?? $this->defaultLanguage,
        ];
        if ($action !== null && $action !== '') {
            $attrs['action'] = $action;
        }

        $this->children[] = $this->element('Say', $attrs, self::escape($text));

        return $this;
    }

    public function play(string $url, ?string $action = null): self
    {
        $attrs = [];
        if ($action !== null && $action !== '') {
            $attrs['action'] = $action;
        }

        $this->children[] = $this->element('Play', $attrs, self::escape($url));

        return $this;
    }

    public function wait(int $timeoutSeconds = 1): self
    {
        $this->children[] = $this->emptyElement('Wait', ['timeout' => (string) max(1, $timeoutSeconds)]);

        return $this;
    }

    public function hangup(): self
    {
        $this->children[] = '<Hangup/>';

        return $this;
    }

    /**
     * @param array<string, string|int> $attributes
     * @param callable(self): void $nestedBuilder
     */
    public function gather(array $attributes, callable $nestedBuilder): self
    {
        $nested = new self($this->defaultVoice, $this->defaultLanguage);
        $nestedBuilder($nested);
        $innerXml = implode('', $nested->children);

        $this->children[] = $this->element('Gather', $attributes, $innerXml);

        return $this;
    }

    /**
     * @param array<string, string> $parameters
     */
    public function startStream(string $name, string $url, ?string $track = 'inbound', array $parameters = []): self
    {
        $paramXml = '';
        foreach ($parameters as $paramName => $paramValue) {
            $paramXml .= $this->emptyElement('Parameter', [
                'name' => $paramName,
                'value' => $paramValue,
            ]);
        }

        $streamAttrs = [
            'name' => $name,
            'url' => $url,
        ];
        if ($track !== null && $track !== '') {
            $streamAttrs['track'] = $track;
        }

        $streamXml = $this->element('Stream', $streamAttrs, $paramXml);

        $this->children[] = $this->element('Start', [], $streamXml);

        return $this;
    }

    public function stopStream(string $name): self
    {
        $streamXml = $this->emptyElement('Stream', ['name' => $name]);
        $this->children[] = $this->element('Stop', [], $streamXml);

        return $this;
    }

    public function toXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Response>' . implode('', $this->children) . '</Response>';
    }

    public function send(): void
    {
        $xml = $this->toXml();
        if (!headers_sent()) {
            header('Content-Type: text/xml; charset=UTF-8');
            header('Content-Length: ' . strlen($xml));
        }
        echo $xml;
    }

    /**
     * @param array<string, string|int> $attrs
     */
    private function element(string $tag, array $attrs, string $rawInnerXml): string
    {
        return '<' . $tag . $this->formatAttrs($attrs) . '>' . $rawInnerXml . '</' . $tag . '>';
    }

    /**
     * @param array<string, string|int> $attrs
     */
    private function emptyElement(string $tag, array $attrs): string
    {
        return '<' . $tag . $this->formatAttrs($attrs) . '/>';
    }

    /**
     * @param array<string, string|int> $attrs
     */
    private function formatAttrs(array $attrs): string
    {
        $out = '';
        foreach ($attrs as $key => $val) {
            $out .= ' ' . $key . '="' . self::escape((string) $val) . '"';
        }

        return $out;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
