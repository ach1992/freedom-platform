<?php

declare(strict_types=1);

return [
    'private_payment_evidence' => [
        'received' => 'Private payment evidence was received through protected storage. The payment remains pending until the canonical verification/review authority completes it.',
        'invalid' => 'That private payment evidence could not be accepted. Send an approved image and follow the payment instructions; no payment or settlement effect was created.',
        'unavailable' => 'The private payment evidence could not be attached to the current payment authority. Refresh the payment flow before retrying; no bypass or duplicate settlement was created.',
    ],
    'navigation' => [
        'home' => "Welcome to Freedom Platform.\n\nMain menu\nChoose an option below.\n/cancel — Close the current session",
        'buttons' => [
            'my_account' => 'My Account',
            'buy_service' => 'Buy Service',
            'trial_service' => 'Trial Service',
            'my_services' => 'My Services',
            'client_guides' => 'Apps & Guides',
            'support' => 'Support',
            'external_support' => 'External Support',
            'request_cooperation' => 'Request Cooperation',
            'agent_status' => 'Cooperation Status',
            'agent_menu' => 'Agent Menu',
            'admin' => 'Administration',
            'referral' => 'Invite Friends',
            'back' => 'Back',
        ],
        'menu' => [
            'submenu' => 'Menu: :title

Choose an option below.',
        ],
        'referral' => [
            'view' => 'Invite Friends

Your referral code: :referral_token
Your referral link: :referral_link
Inviter set: :has_inviter
Referral locked: :referral_locked',
        ],
        'account' => [
            'view' => "My Account\n\nAccount ID: :public_id\nType: :account_type\nStatus: :account_status\nTier: :tier\nPhone verification: :phone_verification\nIdentity verification: :identity_verification\nIdentity items:\n:identity_items\nJoined: :joined_at\nLast seen: :last_seen_at\n\nWallet\nCash available: :cash_available IRR\nCash on hold: :cash_holds IRR\nPromotional available: :promotional_available IRR\n\nReferral\nYour referral code: :referral_token\nYour referral link: :referral_link\nInviter set: :has_inviter\nReferral locked: :referral_locked",
            'identity_item' => '• :type — :masked (:state)',
            'identity_none' => '• None',
            'not_available' => 'Not available',
            'referral_link_unavailable' => 'Not available until the public bot username is configured',
            'yes' => 'Yes',
            'no' => 'No',
            'values' => [
                'account_type' => ['customer' => 'Customer', 'agent' => 'Agent'],
                'account_status' => [
                    'active' => 'Active', 'limited' => 'Limited', 'suspended' => 'Suspended', 'blocked' => 'Blocked',
                ],
                'tier' => ['new' => 'New', 'normal' => 'Normal', 'loyal' => 'Loyal', 'vip' => 'VIP'],
                'verification' => [
                    'unverified' => 'Unverified', 'pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected',
                ],
                'identity_type' => [
                    'national_id' => 'National ID', 'bank_card' => 'Bank card', 'full_name' => 'Full name',
                ],
            ],
        ],
        'admin' => [
            'control' => "Administrator Control Center\n\nAdministrative settings and tools are shown only when your current permission allows them and are re-authorized when executed.",
            'buttons' => [
                'access' => 'Access Control',
                'global_search' => 'Cross-entity Search',
                'payment_reviews' => 'Payment Reviews',
                'customer_search' => 'Customer Search',
                'usdt_rate' => 'USDT / NOWPayments rate',
                'client_guides' => 'Client Guides',
                'menus' => 'Menus & Buttons',
                'membership' => 'Channel Membership',
            ],
            'payment_reviews' => [
                'list' => "Alternative Payment Reviews\n\n:items\n\nSelect a pending review. Every decision is authorized again at execution time.",
                'empty' => "Alternative Payment Reviews\n\nNo pending C2C, Gift Card, or direct-USDT review is currently available.",
                'item' => "#:number — :kind\nReview: :id\nSubject: :subject\nProvider: :provider\nReference: :reference\nAmount: :amount\nPrivate evidence: :private",
                'detail' => "Payment Review\n\nKind: :kind\nReview: :id\nSubject: :subject\nProvider: :provider\nReference: :reference\nAmount: :amount\nPrivate evidence attached: :private\nC2C candidate reservations:\n:candidates\nRequired USDT confirmations: :confirmations\n\nApproval never bypasses canonical financial guards.",
                'not_available' => 'Not available',
                'yes' => 'Yes',
                'no' => 'No',
                'evidence_caption' => 'Protected :kind evidence for review :review (submission :submission).',
                'kinds' => [
                    'c2c' => 'Card to card',
                    'gift_card' => 'Gift Card',
                    'usdt' => 'Direct USDT',
                ],
                'buttons' => [
                    'open' => 'Open review #:number',
                    'refresh' => 'Refresh',
                    'evidence' => 'View private evidence',
                    'approve' => 'Approve with evidence',
                    'approve_candidate' => 'Approve C2C candidate #:number',
                    'reject' => 'Reject',
                ],
                'input' => [
                    'reject' => 'Send the mandatory rejection reason. No other input is stored in session state.',
                    'c2c' => 'Send the mandatory C2C approval reason. The selected reservation and normalized bank evidence will be revalidated before capture.',
                    'gift_card' => "Send: external_redemption_id | reason\n\nThe full Gift Card code is never requested or stored in Telegram session state. The canonical review service revalidates exact amount/type/provider evidence before settlement.",
                    'usdt' => "Send: confirmations | transaction_time | reason\nExample: 20 | 2026-09-24T12:34:56+00:00 | Verified on BSC explorer\n\nThe stored TXID/network/destination/amount authority is revalidated before settlement.",
                    'invalid' => 'The review input was invalid, stale, unauthorized, or failed a financial-authority check. No bypass was applied.',
                ],
                'notices' => [
                    'changed' => 'The review decision was recorded through the canonical payment authority.',
                    'stale' => 'The selected candidate/review changed. Fresh authority is shown; review it again.',
                ],
            ],
            'menus' => [
                'list' => 'Menu & Button Configuration

Selected menu: :selected

:items

Versions are preview-only until explicitly published.',
                'item' => ':menu_key — active version :version — publication generation :generation',
                'none' => 'No menu version has been created yet.',
                'not_selected' => 'Not selected',
                'version_button' => 'Preview version :version',
                'create_button' => 'Create new version',
                'publish_button' => 'Publish this version',
                'rollback_button' => 'Roll back to this version',
                'changed' => 'The selected version is now active.',
                'stale' => 'The active version changed in the meantime. Fresh state is shown; review the target version again.',
                'editor' => 'Create Menu Version

Send valid JSON matching the example below. Actions are restricted to the safe allowlist. premium_emoji_id requires normal_emoji. Row 9 is reserved for canonical Back/Publish controls.

:example',
                'invalid' => 'The submitted definition is invalid or violates a menu-safety constraint. No version was created.',
                'preview' => 'Menu Preview

Key: :menu_key
Version: :version
Current publication generation: :generation
Items: :items
Active: :active

Preview buttons intentionally execute no real action.',
                'yes' => 'Yes',
                'no' => 'No',
            ],
            'membership' => [
                'list' => "Channel Membership Configuration\n\nRequired channels (:channel_count):\n:channels\n\nMembership rules (:rule_count):\n:rules\n\nOnly safe configuration metadata is shown. Join links are never echoed.",
                'channel_item' => '#:id :key — :title — chat :chat_id — :state v:version',
                'rule_item' => '#:id :key — :action/:audience — :mode — :failure — channels :channels — :state v:version',
                'none' => 'None configured.',
                'list_truncated' => 'Only the first :limit entries of each collection are shown. Use IDs/versions from the current list for mutations.',
                'edit_button' => 'Create / Update / Activate / Disable',
                'refresh_button' => 'Refresh',
                'changed' => 'Configuration target #:id changed through the canonical membership authority.',
                'unchanged' => 'Configuration target #:id was already in the requested state.',
                'replayed' => 'The same configuration request for target #:id was safely replayed.',
                'editor' => "Membership Configuration\n\nSend one complete key=value command. Every write is re-authorized, version-checked, audited and replay-safe. Channel updates require the join_url again because secrets are never readable from Telegram. The submitted join URL is not echoed or stored in session state.\n\n:example",
                'invalid' => 'The command is invalid, stale, unauthorized, or violates a membership safety constraint. No bypass was applied.',
            ],
            'access' => [
                'menu' => "Access Control\n\nManage administrator identities, roles, permission overrides, sensitive approvals and ownership transfers through the canonical AccessControl authorities. Every write is re-authorized at execution time.",
                'not_available' => 'None',
                'not_permitted' => 'Hidden by current permission',
                'yes' => 'Yes',
                'no' => 'No',
                'buttons' => [
                    'target_search' => 'Find / Manage Administrator',
                    'roles' => 'Roles',
                    'permissions' => 'Permission Catalog',
                    'approvals' => 'Pending Sensitive Approvals',
                    'transfers' => 'Ownership Transfers',
                    'enable' => 'Enable Administrator',
                    'suspend' => 'Suspend',
                    'reactivate' => 'Reactivate',
                    'revoke' => 'Revoke Administrator',
                    'role_grant' => 'Grant Role',
                    'role_revoke' => 'Revoke Role',
                    'permission_override' => 'Permission Override',
                    'owner_transfer' => 'Transfer Ownership',
                    'confirm' => 'Confirm sensitive action',
                    'retry_approval' => 'Execute after approval',
                    'cancel_approval' => 'Cancel approval request',
                    'approve_number' => 'Approve #:number',
                    'reject_number' => 'Reject #:number',
                    'accept_transfer_number' => 'Accept transfer #:number',
                    'cancel_transfer_number' => 'Cancel transfer #:number',
                ],
                'target_search' => [
                    'prompt' => "Administrator Target\n\nSend one exact user public ID, Telegram ID, or Telegram username. Existing administrators and eligible non-deleted users can be selected. The lookup is exact and permission-gated.",
                    'not_found' => 'No permitted user matched that exact value.',
                    'ambiguous' => 'That value did not resolve to exactly one user.',
                ],
                'target' => [
                    'view' => "Administrator Target\n\nUser: :user_id\nUsername: :username\nAccount: :account_type / :account_status\nAdministrator status: :admin_status\nOwner: :owner\nPermission version: :permission_version\nLast authenticated: :last_authenticated\nRoles: :roles\nOverrides:\n:overrides\nRecent access audit:\n:audit\n\nButtons are presentation only; every operation is authorized again when executed.",
                ],
                'role_input' => [
                    'prompt' => ":action\n\nSend one exact role code. Available roles: :roles",
                    'invalid' => "That role code is unavailable.\n\n:action\nAvailable roles: :roles",
                    'grant' => 'Grant role',
                    'revoke' => 'Revoke role',
                ],
                'permission_input' => [
                    'prompt' => "Permission Override\n\nSend exactly one command:\nallow permission.code\ndeny permission.code\ninherit permission.code\n\nUse Permission Catalog to discover current codes.",
                    'invalid' => "Invalid permission override command.\n\nUse exactly: allow|deny|inherit followed by one existing permission code.",
                ],
                'roles' => [
                    'list' => "Role Catalog\n\n:items\n\nRole definitions are code-owned in this scope. Assign or revoke existing roles from an administrator target.",
                    'item' => ':code — :type / :active — :permissions permission(s)',
                ],
                'permissions' => [
                    'list' => "Permission Catalog\n\nShowing :shown of :total matching permission(s):\n:items\n\nSend a permission-code or module fragment to filter this catalog.",
                    'item' => ':code — module=:module, risk=:risk, mode=:approval',
                ],
                'confirm' => "Sensitive Access-Control Confirmation\n\n:summary\n\nNo mutation has been applied yet. Confirming creates/uses the canonical sensitive-approval flow; non-Owner administrators require an independent approver.",
                'confirm_failed' => 'The operation could not be accepted. Authorization, target state, recent-authentication or approval policy may have changed. No unconfirmed mutation was applied.',
                'pending' => [
                    'pending' => "Sensitive approval is pending.\n\nAn independent authorized administrator must approve it. This interaction remains bound to the exact requested mutation. After approval, press “Execute after approval”.",
                    'not_ready' => 'The exact mutation is not executable yet. The approval may still be pending, rejected, cancelled, expired, or authorization may have changed.',
                ],
                'approvals' => [
                    'list' => "Pending Sensitive Approvals\n\n:items\n\nApproval does not execute the mutation. The original requester must execute the exact bound action after approval.",
                    'item' => "#:number — :action\nRequester: :requester\nExpires: :expires\nReason: :reason",
                    'failed' => 'The selected approval could not be decided. It may be stale or independent-approval rules may forbid this decision.',
                    'decided' => 'The approval decision was recorded.',
                ],
                'transfers' => [
                    'list' => "Ownership Transfers\n\n:items\n\nRequest/accept/cancel uses the canonical ownership-transfer authority, including recent-authentication, singleton Owner and signed-intent checks.",
                    'item' => "#:number — :owner → :target\nExpires: :expires",
                    'failed' => 'The ownership-transfer action failed closed. Participant state, recent authentication, intent version or authorization may have changed.',
                    'changed' => 'The ownership-transfer action was recorded.',
                ],
                'owner_transfer' => [
                    'request_summary' => 'Request ownership transfer to administrator :target',
                    'accept_summary' => 'Accept the pending ownership transfer addressed to this administrator',
                    'cancel_summary' => 'Cancel the pending ownership transfer requested by the current Owner',
                ],
                'submitting_recovery' => 'A confirmed access-control effect is being reconciled. Retry the same accepted interaction; cancellation and expiry remain fenced while reconciliation is required.',
                'notices' => [
                    'changed' => 'The confirmed access-control change was applied.',
                    'unchanged' => 'The confirmed operation was already in the requested state; no duplicate effect was created.',
                ],
                'audit' => [
                    'confirmed' => 'Telegram Access Control confirmed: :summary',
                    'owner_approval' => 'Owner confirmed the sensitive Telegram Access Control action.',
                    'approved' => 'Sensitive Telegram Access Control action approved.',
                    'rejected' => 'Sensitive Telegram Access Control action rejected.',
                    'cancelled' => 'Sensitive Telegram Access Control approval request cancelled by requester.',
                    'owner_transfer_request' => 'Owner requested ownership transfer through Telegram Access Control.',
                    'owner_transfer_accept' => 'Receiving administrator accepted ownership transfer through Telegram Access Control.',
                    'owner_transfer_cancel' => 'Current Owner cancelled ownership transfer through Telegram Access Control.',
                ],
            ],
            'global_search' => [
                'prompt' => "Cross-entity Search\n\nSend one exact public ID, Telegram ID/username, provider transaction ID, or receipt reference. Results are bounded and permission-filtered; broad/fuzzy listing is not available.",
                'not_found' => 'No permitted entity matched that exact value.',
                'results' => "Cross-entity Search Results\n\n:items\n\nSensitive evidence is masked unless your current permission allows it. Search authorization is checked again on every request.",
                'item' => "#:number — :kind\nID: :id\nState: :state\nOwner: :owner\nProvider: :provider\nReference: :reference\nAmount: :amount",
                'not_available' => 'Not available',
                'masked_suffix' => ' (masked)',
                'amount' => ':amount IRR',
                'kinds' => [
                    'user' => 'User',
                    'order' => 'Order',
                    'payment_intent' => 'Payment Intent',
                    'purchase_settlement' => 'Purchase Settlement',
                    'provider_transaction' => 'Provider Transaction',
                    'card_receipt' => 'Card-to-card Receipt',
                    'gift_submission' => 'Gift-card Submission',
                    'service' => 'Service',
                ],
            ],
            'customer_search' => [
                'prompt' => "Customer Search\n\nSend one exact Telegram ID, Telegram username, or public account ID. The search is exact and permission-gated; broad or fuzzy listing is not available.",
                'not_found' => 'No customer matched that exact value.',
                'ambiguous' => 'That value does not identify one unique customer. No target was selected.',
                'preview' => "Customer Target Preview\n\nTelegram ID: :telegram_id\nAccount ID: :account_id\nUsername: :username\nType: :account_type\nStatus: :account_status\n\nNo direct message has been sent. This step only verifies the selected target.",
                'username_unavailable' => 'Not available',
                'preview_after_message' => "Customer Target Preview\n\nTelegram ID: :telegram_id\nAccount ID: :account_id\nUsername: :username\nType: :account_type\nStatus: :account_status\n\nThe confirmed direct message has been queued once. Its Telegram result and message ID remain linked to the delivery record.",
                'message_button' => 'Send direct message',
                'message_prompt' => "Direct Message\n\nTelegram ID: :telegram_id\nAccount ID: :account_id\nUsername: :username\n\nSend text, a photo, a video, or a document, or choose Forward/Copy below and then send the source message to this private bot chat. Text may contain up to 3500 characters. A photo may include a plain caption up to 1024 characters. Safe Telegram contact buttons can be added before final confirmation. Nothing is sent to the customer until the final confirmation step.",
                'message_invalid' => 'Send text up to 3500 characters, or one supported photo, video, or document. Only a photo may include a plain caption up to 1024 characters.',
                'message_confirmation' => "Final Direct Message Confirmation\n\nTelegram ID: :telegram_id\nAccount ID: :account_id\nUsername: :username\n\nMessage to send:",
                'message_mode_summary' => 'Mode: :mode',
                'message_forward_button' => 'Forward a message',
                'message_copy_button' => 'Copy a message',
                'message_source_prompt' => "Direct Message — :mode\n\nTelegram ID: :telegram_id\nAccount ID: :account_id\nUsername: :username\n\nNow send the source message to this private bot chat. Only its bound Telegram chat/message identity is retained for delivery; source content is not copied into generic session or Outbox state.",
                'message_source_invalid' => 'Send one Telegram message from this private administrator chat, or use Back.',
                'message_source_confirmation' => "Mode: :mode\nSource message ID in the administrator private bot chat: :message_id",
                'message_mode_text' => 'Text',
                'message_mode_photo' => 'Photo',
                'message_mode_video' => 'Video',
                'message_mode_document' => 'Document',
                'message_mode_forward' => 'Forward',
                'message_mode_copy' => 'Copy',
                'message_media_confirmation' => "Type: :type\nMIME: :mime\nSize: :size bytes\nCaption: :caption",
                'message_media_type_photo' => 'Photo',
                'message_media_type_video' => 'Video',
                'message_media_type_document' => 'Document',
                'message_media_caption_none' => 'None',
                'message_buttons_add_button' => 'Add safe buttons',
                'message_buttons_edit_button' => 'Edit safe buttons',
                'message_buttons_clear_button' => 'Remove buttons',
                'message_buttons_prompt' => "Safe Direct-Message Buttons\n\nTelegram ID: :telegram_id\nCurrent buttons: :count\n\nSend 1-8 lines in this exact format:\nLabel | https://t.me/username\n\nOnly approved Telegram contact URLs are accepted. Callbacks, arbitrary URLs, styles, and payment actions are not available here.",
                'message_buttons_invalid' => 'The button list is invalid. Use 1-8 lines as: Label | https://t.me/username.',
                'message_buttons_summary' => "Safe buttons (:count):\n:buttons",
                'message_text_preview_truncated' => '[Long text preview truncated here; the durable draft remains unchanged.]',
                'message_confirm_button' => 'Confirm and queue',
                'message_queued' => 'The direct message was queued once. Its Telegram result and message ID remain linked to the delivery record.',
                'message_unavailable' => 'The draft expired or the selected target changed. Review the current target and compose a new message.',
                'message-permission-unavailable' => 'Direct-message permission is not currently available.',
                'message-authorization-lost' => 'Direct-message authorization changed. Review the target again.',
                'message-media-authorization-lost' => 'Direct-media authorization changed. Review the target again.',
                'message-source-authorization-lost' => 'Direct source-message authorization changed. Review the target again.',
                'message-buttons-authorization-lost' => 'Direct-message button authorization changed. Review the target again.',
            ],
            'usdt_rate' => [
                'view' => "Manual USDT Rate\n\nCurrent rate: :rate IRR per USDT\nSource: :source\nManaged version: :version\n\nThe same rate is used for direct USDT and, under the current Owner policy, as the USD pricing proxy for NOWPayments.",
                'unset' => "Manual USDT Rate\n\nNo managed rate or bootstrap fallback is currently available.\n\nThis setting is the shared direct-USDT rate and the USD pricing proxy for NOWPayments.",
                'edit_button' => 'Change rate',
                'prompt' => "Change Manual USDT Rate\n\nSend the new IRR amount per USDT as a number only.\nExample: 900000\n\nPersian and Arabic digits are also accepted.",
                'confirm' => "Confirm Manual USDT Rate Change\n\nNew rate: :rate IRR per USDT\n\nThis rate affects future direct-USDT pricing and the NOWPayments USD pricing proxy. Existing payment snapshots are not rewritten.\n\nNo change is saved until you press “Confirm rate change”.",
                'confirm_button' => 'Confirm rate change',
                'invalid' => "The rate is invalid or outside the configured allowed bounds.\n\nSend a valid IRR amount per USDT.",
                'updated_notice' => 'The managed rate was saved successfully.',
                'not_managed' => 'None',
                'sources' => [
                    'managed' => 'Managed',
                    'bootstrap' => 'Bootstrap fallback',
                ],
            ],
        ],
        'purchase' => [
            'list' => 'Buy Service

:items

Page :page of :total_pages — :total_items available option(s)',
            'list_item' => '#:number — :plan
Category: :category
Mode: :mode
Base price: :price IRR
Duration: :duration days',
            'empty' => 'Buy Service

No currently eligible service offering is available for your account.',
            'offering_button' => 'Option #:number',
            'previous' => 'Previous',
            'next' => 'Next',
            'not_available' => 'Not available',
            'detail' => 'Service Option

Category: :category
Plan: :plan
Mode: :mode
Base price: :price IRR
Duration: :duration days
Data: :data
Device limit: :devices

This is catalog discovery only. No quote, payment, capacity reservation, order, or provisioning has been created yet.',
            'quote_button' => 'View quote',
            'quote' => 'Service Purchase Quote

Quote ID: :quote_id
Plan: :plan
Base price: :base_price :currency
Effective price: :effective_price :currency
Discount: :discount :currency
Final amount: :final_price :currency
Valid until: :expires_at (Tehran time)

This is a recorded commercial snapshot only. No payment, capacity reservation, order, or provisioning has been created yet.',
            'discount' => [
                'button' => 'Apply discount code',
                'prompt' => 'Send the discount code in one message. The code is not copied into conversation state, callbacks, or reply text. Use Back to continue without a discount.',
                'rejected' => 'The discount code or current Quote cannot be applied. No discount, payment reservation, Order, or Payment Intent was committed. You can send another code or go Back.',
            ],
            'payment_methods_button' => 'Payment methods',
            'payment_methods' => [
                'list' => "Eligible Payment Methods\n\n:items\n\nThis list comes from the current persisted PAY-001 decision. A stable Order is now awaiting payment, but no Payment Intent, wallet debit, gateway request, or payment has been created yet.",
                'item' => ':number. :method',
                'selected' => "Selected payment method: :method\n\nYour Order remains awaiting payment. No Payment Intent, wallet debit, gateway request, or payment has been created yet.",
                'empty' => "Payment Methods\n\nNo payment method is currently eligible for this Quote.\n\nNo Order, Payment Intent, or financial effect has been created.",
                'gift_card_payment' => [
                    'continue' => 'Continue with Gift Card',
                    'retry' => 'Refresh Gift Card types',
                    'no_types' => "No active Gift Card type is currently available for this payment.\n\nNo payment or Gift Card submission was created.",
                    'types' => "Choose the Gift Card type you want to submit.\n\n:items\n\nThe configured evidence/verification policy for the selected type is enforced after selection. Choosing a type does not create a payment yet.",
                    'type_button' => ':name — :currency',
                    'type_item' => ':number. :name | Brand: :brand | Region: :region | Face currency: :currency',
                    'region_any' => 'Any',
                    'face_value_prompt' => "Selected type: :type\n\nSend the Gift Card face value as a positive whole number in :currency.\n\nNo Gift Card/payment state is created until the configured evidence is submitted.",
                    'face_value_invalid' => 'That face value is invalid. Send a positive whole number in :currency for :type.',
                    'code_prompt' => "Gift Card type: :type\nClaimed face value: :face_value :currency\n\nSend the Gift Card code in your next message.\n\nThe full code is transient input and is never copied into Telegram session/callback/presentation state.",
                    'image_prompt' => "Gift Card type: :type\nClaimed face value: :face_value :currency\n\nSend one image of the Gift Card as private evidence. Do not put the code in the caption. The image is stored only through the protected private-media authority.",
                    'either_prompt' => "Gift Card type: :type\nClaimed face value: :face_value :currency\n\nSend either the Gift Card code as text or one private image. If you choose an image, any optional caption is treated as transient code evidence and is never stored in Telegram session state.",
                    'both_prompt' => "Gift Card type: :type\nClaimed face value: :face_value :currency\n\nSend one private image and put the Gift Card code in that image message caption. The caption remains transient; the image stays behind the protected private-media reference boundary.",
                    'evidence_invalid' => 'The configured Gift Card evidence could not be accepted. Follow the requested code/image mode and try again. No second provider/payment effect was created.',
                    'code_invalid' => "The Gift Card code could not be accepted. Check the code and send it again.\n\nThe full code will not be echoed back. No second provider/payment effect is created by this retry.",
                    'image_evidence' => 'Private image evidence',
                    'pending_verification' => "Gift Card evidence submitted.\n\nSubmission: :submission_id\nType: :type\nEvidence: :masked_code\nClaimed face value: :face_value :currency\nStatus: Pending configured provider verification/reconciliation\n\nThis is not payment confirmation, settlement, or service provisioning.",
                    'pending_manual_review' => "Gift Card submitted for manual review.\n\nSubmission: :submission_id\nType: :type\nEvidence: :masked_code\nClaimed face value: :face_value :currency\nStatus: Pending manual review\n\nThis is not payment confirmation, settlement, or service provisioning. Restricted Gift Card evidence is not shown here.",
                ],
                'usdt_payment' => [
                    'unavailable' => 'USDT instructions are no longer available for this Order/Quote. Payment methods were refreshed. No payment, settlement, or provisioning effect was created.',
                    'instructions' => "USDT payment prepared\n\nNetwork: :network\nExact amount: :amount USDT\nDestination: :address\nQuote expires: :expires_at (Tehran time)\n\nAfter transferring the exact amount on BEP20, either send the TXID as text or optionally attach one private screenshot and put the TXID in that image caption. Private evidence stays behind the protected media-reference boundary. Settlement still requires authorized verification/review.",
                    'txid_invalid' => "That TXID is invalid. Send one EVM transaction hash in the form 0x followed by 64 hexadecimal characters.\n\nNetwork: :network\nExact amount: :amount USDT\nDestination: :address\nQuote expires: :expires_at (Tehran time)\n\nNo second payment or settlement effect was created by this invalid input.",
                    'submitted' => "USDT transaction submitted\n\nSubmission: :submission_id\nTXID: :txid\nStatus: Pending authorized review/verification\n\nThis is not payment confirmation or settlement. Service provisioning has not started and capture remains inside the existing verified-transfer/settlement authority.",
                ],
                'wallet_payment' => [
                    'continue' => 'Continue with wallet',
                    'retry' => 'Retry wallet payment',
                    'unavailable' => "Wallet payment is not currently available for this Order, or the available wallet balance is insufficient.\n\nNo Payment Intent, hold, settlement, or debit was committed by this failed attempt.",
                    'confirm' => "Confirm wallet payment\n\nAmount: :amount :currency\nAvailable wallet balance after the temporary hold: :available :currency\n\nThe amount is reserved but has not been debited yet. Confirm to capture the wallet payment. Back releases the wallet hold.",
                    'confirm_button' => 'Confirm wallet payment',
                    'paid' => "Wallet payment completed\n\nOrder: :order_id\nPaid amount: :amount :currency\n\nPayment and settlement are complete. Provisioning and Service delivery have not started in this step.",
                ],
                'card_to_card_payment' => [
                    'continue' => 'Continue with card to card',
                    'retry' => 'Retry card-to-card payment',
                    'unavailable' => 'Card-to-card payment is not currently available for this Order. No new Payment Intent or payable reservation was committed by this failed attempt.',
                    'instructions' => "Card-to-card payment reserved\n\nExact amount: :amount :currency\nDestination: :card\nTransfer window ends: :expires_at (Tehran time)\n\nA separate protected message contains the full destination card number and a copy button. Transfer exactly the reserved amount. This screen does not confirm payment; settlement requires authoritative bank/review evidence.",
                    'protected_instructions' => "Protected card-to-card transfer details\n\nCard number: :card_number\nExact amount: :amount :currency\nTransfer window ends: :expires_at (Tehran time)\n\nTransfer exactly this amount to this card. Sending the transfer or pressing buttons does not by itself mark the Order paid; authoritative bank/review evidence is required.",
                    'copy_card' => 'Copy card number',
                ],
                'methods' => [
                    'wallet' => 'Wallet',
                    'card_to_card' => 'Card to card',
                    'gift_card' => 'Gift card',
                    'usdt_bep20' => 'USDT (BEP20)',
                    'zarinpal' => 'Zarinpal',
                    'nowpayments' => 'NOWPayments',
                    'other' => 'Payment method #:number',
                    'other_selected' => 'Selected payment method',
                ],
            ],
        ],
        'services' => [
            'list' => "My Services\n\n:items\n\nPage :page of :total_pages — :total_items service(s)",
            'list_item' => "#:number — :public_id\n:plan · :server\nState: :state",
            'empty' => "My Services\n\nYou do not have any services yet.",
            'service_button' => 'Service #:number',
            'previous' => 'Previous',
            'next' => 'Next',
            'not_available' => 'Not available',
            'allowed_actions_none' => 'None currently available',
            'allowed_actions_separator' => ', ',
            'lifecycle' => [
                'button' => [
                    'reset_usage' => 'Reset usage',
                    'suspend' => 'Suspend service',
                    'activate' => 'Activate service',
                    'rotate_subscription_link' => 'Rotate subscription link',
                    'refresh_details' => 'Refresh details',
                    'delete' => 'Delete / retire service',
                ],
                'confirm_button' => 'Confirm action',
                'confirm' => [
                    'reset_usage' => 'Confirm resetting usage for this Service? The accepted action is executed through the replay-safe mutation queue.',
                    'suspend' => 'Confirm suspending this Service? Access can stop until the Service is activated again.',
                    'activate' => 'Confirm activating this Service again?',
                    'rotate_subscription_link' => 'Confirm rotating the subscription link? The previous link may become invalid immediately and new details are delivered only through the protected path.',
                    'refresh_details' => 'Confirm refreshing the current Service state from the Panel? This only updates synchronization evidence.',
                    'delete' => 'Confirm deleting / retiring this Service? This does not create an automatic refund, the remote action is executed once, and financial and audit history is preserved.',
                ],
                'result' => [
                    'queued' => 'The Service request was accepted and queued through the controlled mutation authority. Remote outcomes and uncertain recovery continue through the normal execution path.',
                    'refreshed' => 'The Service was checked again against the Panel and synchronization evidence was refreshed.',
                    'refresh_attention' => 'The Service check completed, but a mismatch or provider failure was recorded for attention. No mutating action was blindly repeated.',
                    'unavailable' => 'This action is no longer allowed by the current Service state, policy, or Panel capability. Return to Service details to see the current actions.',
                ],
            ],
            'resend' => [
                'button' => 'Resend secure details',
                'queued' => "Secure Service details were queued for protected delivery.\n\nNo credential, link, or QR data is shown in this confirmation.",
                'temporarily_blocked' => "Secure Service details cannot be resent right now because another Service operation or delivery is still in progress.\n\nPlease try again later.",
                'unavailable' => 'This Service is no longer available for secure resend.',
            ],
            'search' => [
                'button' => 'Search',
                'prompt' => "Search My Services\n\nSend the exact Service ID, Order ID, or service username.\nSearch is private and exact.",
                'not_found' => "No matching service was found in your account.\n\nTry the exact Service ID, Order ID, or service username.",
                'ambiguous' => "More than one of your services uses that username.\n\nSearch by exact Service ID or Order ID to choose one safely.",
            ],
            'detail' => "Service Details\n\nService ID: :public_id\nPlan: :plan\nServer: :server\nLifecycle: :state\nProvisioned: :provisioned_at\nAllowed actions: :allowed_actions\n\nSynchronization\nEvidence state: :sync_state\nRemote evidence: :remote_disposition\nRemote status: :remote_status\nData limit: :data_limit\nUsed: :used\nRemaining: :remaining\nExpires: :expires_at\nObserved: :observed_at",
            'values' => [
                'lifecycle' => [
                    'active' => 'Active', 'suspended' => 'Suspended', 'retired' => 'Retired',
                ],
                'sync_state' => [
                    'none' => 'No synchronization evidence',
                    'current' => 'Current',
                    'cached' => 'Cached — current synchronization unavailable; showing last confirmed facts',
                    'stale' => 'Stale — remote facts hidden',
                ],
                'remote_disposition' => [
                    'present' => 'Present',
                    'missing' => 'Missing',
                    'unavailable' => 'Temporarily unavailable',
                    'identity_mismatch' => 'Identity mismatch',
                ],
                'remote_status' => [
                    'active' => 'Active', 'suspended' => 'Suspended', 'expired' => 'Expired', 'disabled' => 'Disabled', 'unknown' => 'Unknown',
                ],
                'action' => [
                    'renew' => 'Renew',
                    'add_data' => 'Add data',
                    'add_days' => 'Add days',
                    'add_data_days' => 'Add data and days',
                    'reset_usage' => 'Reset usage',
                    'suspend' => 'Suspend',
                    'activate' => 'Activate',
                    'rotate_subscription_link' => 'Rotate subscription link',
                    'refresh_details' => 'Refresh details',
                    'delete' => 'Delete / retire',
                ],
            ],
        ],
    ],
    'client_guides' => [
        'empty' => "Apps & Guides\n\nNo guide is currently available for your account.",
        'list' => "Apps & Guides — page :page/:pages\n\n:items\n\nUse a button below to open the registered official HTTPS destination.",
        'admin' => [
            'add_button' => 'Add resource',
            'none' => 'No resources.',
            'list' => "Client Guide Catalogue — page :page/:pages\n\n:items\n\nSelect a resource to edit it, including state or sort order.",
            'mode' => ['create' => 'Create', 'edit' => 'Edit'],
            'create_template' => "code=android\nplatform=android\nlanguage=any\naudience=all\ntier=-\ntag=-\nsort=10\nstate=active\nurl=https://example.com/client\nemoji=📱\npremium=-\ntitle_fa=عنوان فارسی\ntitle_en=English title\ndescription_fa=-\ndescription_en=-\ntutorial_fa=-\ntutorial_en=-\nreason=describe_the_change",
            'editor' => "Client Guide — :mode\n\nSend the complete key=value definition below. Use - for optional empty values. Set state=disabled to hide a resource without deleting history; change sort to reorder. URLs must be safe HTTPS destinations.\n\n:current",
            'invalid' => 'The definition is invalid, stale, unauthorized, or references an unavailable tier/tag. Review every key and send the complete definition again.',
        ],
    ],
    'broadcast' => [
        'menu' => 'Broadcasts',
        'title' => 'Broadcast management',
        'new_campaign' => 'Create broadcast',
        'manage_campaign' => 'Manage broadcast',
        'content_mode' => 'Choose the content mode.',
        'content_new_text' => 'New text',
        'content_copy' => 'Copy message',
        'content_forward' => 'Forward message',
        'source_kind' => 'Choose the source message type.',
        'await_text' => 'Send the broadcast text.',
        'await_source' => 'Send or forward the source message here.',
        'audience' => 'Choose the audience.',
        'audience_all' => 'All users',
        'audience_custom' => 'Advanced filters',
        'await_audience' => 'Send audience filters as one key=value per line. Send only all for every user.',
        'review' => 'Preview is ready. Estimated recipients: :count',
        'owner_test' => 'Send Owner test',
        'owner_test_required' => 'A successful Owner test is required before broad delivery.',
        'start_now' => 'Start now',
        'schedule' => 'Schedule',
        'await_schedule' => 'Send an ISO 8601 timestamp with timezone, for example 2026-09-20T18:30:00+03:30',
        'progress' => 'State: :state | total: :total | sent: :sent | queued: :queued | failed: :failed | uncertain: :uncertain',
        'pause' => 'Pause',
        'resume' => 'Resume',
        'cancel' => 'Cancel',
        'retry_failed' => 'Retry definitive failures',
        'edit' => 'Edit content',
        'buttons' => 'Change buttons',
        'pin' => 'Pin',
        'unpin' => 'Unpin',
        'delete' => 'Delete messages',
        'back' => 'Back',
        'invalid' => 'The broadcast input is invalid.',
        'saved' => 'Broadcast saved.',
        'scheduled' => 'Broadcast scheduled.',
        'started' => 'Broadcast started.',
        'cancelled' => 'Broadcast cancelled.',
        'paused' => 'Broadcast paused.',
        'resumed' => 'Broadcast resumed.',
        'not_available' => 'This broadcast is unavailable.',
        'campaign_id' => 'Campaign ID: :id',
        'owner_refresh' => 'Check Owner test status',
        'owner_test_state' => 'Owner test state: :state',
        'owner_test_succeeded' => 'Owner test completed successfully.',
        'owner_test_pending' => 'Owner test is not final yet. Check its status again shortly.',
        'refresh' => 'Refresh status',
        'apply' => 'Apply to delivered messages',
        'await_buttons' => 'Send one button per line as purpose | label | https://url. Send only none to remove buttons.',
        'await_text_edit' => 'Send the new message text.',
        'await_caption_edit' => 'Send the new caption. Send only none to clear the caption.',
        'preview_text' => "Text preview:\n:text",
        'preview_source' => 'Source: :mode | kind: :kind | message_id: :message_id',
        'lifecycle_review' => 'Message version :version is ready for “:action”.',
        'lifecycle_progress' => ':action: :done of :total final | uncertain: :uncertain',
        'source_kinds' => [
            'text' => 'Text',
            'photo' => 'Photo',
            'video' => 'Video',
            'animation' => 'Animation',
            'audio' => 'Audio',
            'document' => 'Document',
        ],
        'states' => [
            'draft' => 'Draft',
            'scheduled' => 'Scheduled',
            'active' => 'Sending',
            'paused' => 'Paused',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
        ],
    ],
];
