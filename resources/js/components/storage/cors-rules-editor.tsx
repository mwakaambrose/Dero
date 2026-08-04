import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { CorsRuleInput } from '@/types';

const METHODS = ['GET', 'PUT', 'POST', 'DELETE', 'HEAD'];

export const EMPTY_CORS_RULE: CorsRuleInput = {
    allowedOrigins: ['*'],
    allowedMethods: ['GET'],
    allowedHeaders: [],
    exposeHeaders: [],
    maxAgeSeconds: null,
};

/**
 * Lines are kept as typed — including blank ones — so a newline mid-edit does
 * not fight the cursor. Blanks are dropped when the form is submitted.
 */
function LineList({
    id,
    label,
    hint,
    value,
    placeholder,
    error,
    onChange,
}: {
    id: string;
    label: string;
    hint?: string;
    value: string[];
    placeholder: string;
    error?: string;
    onChange: (next: string[]) => void;
}) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>{label}</Label>
            <Textarea
                id={id}
                rows={2}
                spellCheck={false}
                className="font-mono text-xs"
                placeholder={placeholder}
                value={value.join('\n')}
                onChange={(event) => onChange(event.target.value.split('\n'))}
            />
            {hint ? (
                <p className="text-xs text-muted-foreground">{hint}</p>
            ) : null}
            <InputError message={error} />
        </div>
    );
}

export function CorsRulesEditor({
    rules,
    errors,
    onChange,
}: {
    rules: CorsRuleInput[];
    errors: Record<string, string>;
    onChange: (next: CorsRuleInput[]) => void;
}) {
    const updateRule = (index: number, patch: Partial<CorsRuleInput>) => {
        onChange(
            rules.map((rule, position) =>
                position === index ? { ...rule, ...patch } : rule,
            ),
        );
    };

    const toggleMethod = (index: number, method: string) => {
        const current = rules[index].allowedMethods;

        updateRule(index, {
            allowedMethods: current.includes(method)
                ? current.filter((entry) => entry !== method)
                : [...current, method],
        });
    };

    return (
        <div className="grid gap-4">
            {rules.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No CORS rules. Browsers cannot read this bucket directly
                    from another origin until you add one.
                </p>
            ) : null}

            {rules.map((rule, index) => (
                <div
                    // Rules have no identity of their own; position is what the
                    // server stores and what S3 matches on, so it is the key.
                    key={index}
                    data-test={`cors-rule-${index}`}
                    className="grid gap-4 rounded-lg border border-border p-4"
                >
                    <div className="flex items-center justify-between">
                        <h4 className="font-mono text-[11px] tracking-[0.16em] text-muted-foreground uppercase">
                            Rule {index + 1}
                        </h4>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            data-test={`cors-rule-remove-${index}`}
                            onClick={() =>
                                onChange(
                                    rules.filter(
                                        (_, position) => position !== index,
                                    ),
                                )
                            }
                        >
                            <Trash2 className="size-3.5" />
                            Remove
                        </Button>
                    </div>

                    <LineList
                        id={`cors-origins-${index}`}
                        label="Allowed origins"
                        hint="One per line. Use * to allow any origin."
                        placeholder="https://app.example.com"
                        value={rule.allowedOrigins}
                        error={errors[`corsRules.${index}.allowedOrigins`]}
                        onChange={(allowedOrigins) =>
                            updateRule(index, { allowedOrigins })
                        }
                    />

                    <div className="grid gap-1.5">
                        <Label>Allowed methods</Label>
                        <div className="flex flex-wrap gap-2">
                            {METHODS.map((method) => {
                                const active =
                                    rule.allowedMethods.includes(method);

                                return (
                                    <button
                                        key={method}
                                        type="button"
                                        aria-pressed={active}
                                        data-test={`cors-method-${index}-${method}`}
                                        onClick={() =>
                                            toggleMethod(index, method)
                                        }
                                        className={`rounded-md border px-2.5 py-1 font-mono text-xs transition-colors ${
                                            active
                                                ? 'border-primary bg-primary text-primary-foreground'
                                                : 'border-border text-muted-foreground hover:text-foreground'
                                        }`}
                                    >
                                        {method}
                                    </button>
                                );
                            })}
                        </div>
                        <InputError
                            message={
                                errors[`corsRules.${index}.allowedMethods`]
                            }
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <LineList
                            id={`cors-allowed-headers-${index}`}
                            label="Allowed headers"
                            placeholder="content-type"
                            value={rule.allowedHeaders}
                            error={errors[`corsRules.${index}.allowedHeaders`]}
                            onChange={(allowedHeaders) =>
                                updateRule(index, { allowedHeaders })
                            }
                        />

                        <LineList
                            id={`cors-expose-headers-${index}`}
                            label="Expose headers"
                            placeholder="etag"
                            value={rule.exposeHeaders}
                            error={errors[`corsRules.${index}.exposeHeaders`]}
                            onChange={(exposeHeaders) =>
                                updateRule(index, { exposeHeaders })
                            }
                        />
                    </div>

                    <div className="grid gap-1.5 sm:max-w-48">
                        <Label htmlFor={`cors-max-age-${index}`}>
                            Max age (seconds)
                        </Label>
                        <Input
                            id={`cors-max-age-${index}`}
                            type="number"
                            min={0}
                            inputMode="numeric"
                            placeholder="Not set"
                            value={rule.maxAgeSeconds ?? ''}
                            onChange={(event) =>
                                updateRule(index, {
                                    maxAgeSeconds:
                                        event.target.value === ''
                                            ? null
                                            : Number(event.target.value),
                                })
                            }
                        />
                        <InputError
                            message={errors[`corsRules.${index}.maxAgeSeconds`]}
                        />
                    </div>
                </div>
            ))}

            <div>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="cors-rule-add"
                    onClick={() => onChange([...rules, { ...EMPTY_CORS_RULE }])}
                >
                    <Plus className="size-3.5" />
                    Add rule
                </Button>
            </div>
        </div>
    );
}
