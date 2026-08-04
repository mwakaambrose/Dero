export type BucketVisibility = 'private' | 'public-read';

export type VisibilityOption = {
    value: string;
    label: string;
    description: string;
};

/**
 * A CORS rule as the settings form edits it. The stored rows keep the S3
 * element names; the controller translates between the two.
 */
export type CorsRuleInput = {
    allowedOrigins: string[];
    allowedMethods: string[];
    allowedHeaders: string[];
    exposeHeaders: string[];
    maxAgeSeconds: number | null;
};
