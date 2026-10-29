export type SenderAddressPurpose = 'news' | 'noreply';

export type PurposeOption = { value: SenderAddressPurpose; label: string };

export type TestResult = {
    ok: boolean;
    from: string;
    recipients: string[];
    mailgun_domain: string;
    message_id: string | null;
    error: string | null;
    warning: string | null;
    status: string;
    checks: { mailgun_state: string | null; spf: boolean; dkim: boolean; dmarc: boolean };
    check_message: string;
    tested_at: string;
};

export type SenderAddress = {
    id: number;
    address: string;
    purpose: SenderAddressPurpose;
    label: string | null;
    enabled: boolean;
    last_test: TestResult | null;
};
