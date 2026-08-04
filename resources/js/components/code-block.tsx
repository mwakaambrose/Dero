import { Check, Copy } from 'lucide-react';
import { useMemo } from 'react';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';

export type CodeLanguage = 'bash' | 'ini' | 'python';

type TokenType =
    | 'comment'
    | 'string'
    | 'keyword'
    | 'number'
    | 'function'
    | 'operator'
    | 'property'
    | 'plain';

type Rule = { type: TokenType; pattern: RegExp };

type Token = { type: TokenType; value: string };

/*
 | A small, dependency-free highlighter.
 |
 | The landing page shows four short snippets in three languages; pulling in a
 | full grammar engine for that would cost more bytes than the page itself.
 | Every pattern below must use non-capturing groups only — the rules are fused
 | into one alternation and the matching group index identifies the token type.
 */
const RULES: Record<CodeLanguage, Rule[]> = {
    bash: [
        { type: 'comment', pattern: /#[^\n]*/ },
        {
            type: 'string',
            pattern: /"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/,
        },
        { type: 'string', pattern: /(?:https?|s3):\/\/[^\s"'\\]+/ },
        { type: 'property', pattern: /(?<=^|\s)--?[A-Za-z][\w-]*/ },
        {
            type: 'keyword',
            pattern:
                /(?<=^|\s)(?:aws|rclone|git|cd|ls|cp|mv|mkdir|curl|export|sudo|composer|npm|php|artisan|systemctl|nginx)(?=\s|$)/,
        },
        {
            type: 'function',
            pattern:
                /(?<=\s)(?:s3|configure|clone|install|ci|run|migrate|mb|rb|sync)(?=\s|$)/,
        },
        { type: 'number', pattern: /\b\d+(?:\.\d+)?\b/ },
        { type: 'operator', pattern: /&&|\|\||[|><\\]/ },
    ],
    ini: [
        { type: 'comment', pattern: /[#;][^\n]*/ },
        { type: 'keyword', pattern: /^\[[^\]\n]*\]/m },
        { type: 'property', pattern: /^[\w.-]+(?=\s*=)/m },
        { type: 'operator', pattern: /=/ },
        { type: 'string', pattern: /(?:https?|s3):\/\/\S+/ },
        {
            type: 'number',
            pattern: /(?<==\s*)(?:true|false|\d+(?:\.\d+)?)(?=\s*$)/m,
        },
    ],
    python: [
        { type: 'comment', pattern: /#[^\n]*/ },
        {
            type: 'string',
            pattern:
                /"""[\s\S]*?"""|'''[\s\S]*?'''|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'/,
        },
        {
            type: 'keyword',
            pattern:
                /\b(?:import|from|as|def|class|return|with|for|while|in|if|elif|else|try|except|finally|raise|None|True|False|and|or|not|lambda|yield)\b/,
        },
        { type: 'function', pattern: /\b[A-Za-z_]\w*(?=\()/ },
        { type: 'property', pattern: /(?<=\.)[A-Za-z_]\w*/ },
        { type: 'number', pattern: /\b\d+(?:\.\d+)?\b/ },
        { type: 'operator', pattern: /[=+\-*/%<>!]+/ },
    ],
};

const TOKEN_CLASS: Record<TokenType, string> = {
    comment: 'text-code-comment italic',
    string: 'text-code-string',
    keyword: 'text-code-keyword',
    number: 'text-code-number',
    function: 'text-code-function',
    operator: 'text-code-operator',
    property: 'text-code-property',
    plain: '',
};

function buildMatcher(rules: Rule[]): RegExp {
    return new RegExp(
        rules.map((rule) => `(${rule.pattern.source})`).join('|'),
        'gm',
    );
}

function tokenize(code: string, language: CodeLanguage): Token[] {
    const rules = RULES[language];
    const matcher = buildMatcher(rules);
    const tokens: Token[] = [];
    let cursor = 0;

    for (const match of code.matchAll(matcher)) {
        const index = match.index ?? 0;

        if (index > cursor) {
            tokens.push({ type: 'plain', value: code.slice(cursor, index) });
        }

        const groupIndex = match
            .slice(1)
            .findIndex((group) => group !== undefined);
        tokens.push({
            type: groupIndex === -1 ? 'plain' : rules[groupIndex].type,
            value: match[0],
        });

        cursor = index + match[0].length;
    }

    if (cursor < code.length) {
        tokens.push({ type: 'plain', value: code.slice(cursor) });
    }

    return tokens;
}

/**
 * Split tokens on newlines so each source line can be rendered as its own row,
 * which is what lets the gutter line numbers stay aligned with wrapped panels.
 */
function tokenizeLines(code: string, language: CodeLanguage): Token[][] {
    const lines: Token[][] = [[]];

    for (const token of tokenize(code, language)) {
        const parts = token.value.split('\n');

        parts.forEach((part, index) => {
            if (index > 0) {
                lines.push([]);
            }

            if (part !== '') {
                lines[lines.length - 1].push({ type: token.type, value: part });
            }
        });
    }

    return lines;
}

export function CodeBlock({
    code,
    language,
    filename,
    showLineNumbers = true,
    className,
}: {
    code: string;
    language: CodeLanguage;
    filename?: string;
    showLineNumbers?: boolean;
    className?: string;
}) {
    const [copiedText, copy] = useClipboard();
    const copied = copiedText === code;
    const lines = useMemo(
        () => tokenizeLines(code, language),
        [code, language],
    );

    return (
        <div
            className={cn(
                'group overflow-hidden rounded-xl border border-border bg-code shadow-sm',
                className,
            )}
        >
            <div className="flex items-center gap-3 border-b border-border/70 bg-code-header px-4 py-2.5">
                <span aria-hidden className="flex items-center gap-1.5">
                    <span className="size-2.5 rounded-full bg-destructive/50" />
                    <span className="size-2.5 rounded-full bg-primary/60" />
                    <span className="size-2.5 rounded-full bg-chart-3/50" />
                </span>
                <span className="truncate font-mono text-xs text-muted-foreground">
                    {filename}
                </span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={() => void copy(code)}
                    aria-label={copied ? 'Copied' : 'Copy code'}
                    className="ml-auto h-7 gap-1.5 px-2 text-xs text-muted-foreground hover:text-foreground"
                >
                    {copied ? (
                        <Check className="size-3.5 text-chart-3" />
                    ) : (
                        <Copy className="size-3.5" />
                    )}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>

            <pre className="overflow-x-auto p-4 font-mono text-[13px] leading-6">
                <code>
                    {lines.map((tokens, lineIndex) => (
                        <span
                            key={lineIndex}
                            className="grid grid-cols-[auto_1fr] gap-4"
                        >
                            {showLineNumbers && (
                                <span
                                    aria-hidden
                                    className="w-5 shrink-0 text-right text-muted-foreground/45 select-none"
                                >
                                    {lineIndex + 1}
                                </span>
                            )}
                            <span className="text-foreground/85">
                                {tokens.length === 0
                                    ? '\u00a0'
                                    : tokens.map((token, tokenIndex) => (
                                          <span
                                              key={tokenIndex}
                                              className={
                                                  TOKEN_CLASS[token.type]
                                              }
                                          >
                                              {token.value}
                                          </span>
                                      ))}
                            </span>
                        </span>
                    ))}
                </code>
            </pre>
        </div>
    );
}
