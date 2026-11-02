<?php

return [

    'booking' => [
        'pending_approval' => [
            'title' => 'Booking awaiting your approval',
            'body' => ':member requested :space on :date at :time.',
        ],
        'approved' => [
            'title' => 'Booking Approved',
            'body' => 'Your booking for :space on :date at :time is confirmed.',
        ],
        'rejected' => [
            'title' => 'Your booking was not approved',
            'body' => 'Your booking for :space on :date was declined, and :points points were returned to your wallet.',
        ],
        'rejected_no_refund' => [
            'title' => 'Your booking was not approved',
            'body' => 'Your booking for :space on :date was declined.',
        ],
        'lapsed' => [
            'title' => 'Your booking was not confirmed',
            'body' => 'Your booking for :space started before it was approved, and the amount was returned to your wallet.',
        ],
        'lapsed_no_refund' => [
            'title' => 'Your booking was not confirmed',
            'body' => 'Your booking for :space started before it was approved.',
        ],
        'payment_expired' => [
            'title' => 'Booking cancelled for non-payment',
            'body' => 'The time to pay for your booking of :space ran out, and the slot is now free for others.',
        ],
        'no_show' => [
            'title' => 'You missed your booking',
            'body' => 'Your booking for :space on :date ended without a check-in.',
        ],
        'reminder' => [
            'title' => 'Upcoming Booking Reminder',
            'body' => 'Your booking for :space starts in :minutes minutes.',
        ],
        'extended' => [
            'title' => 'Booking Extended',
            'body' => 'Your booking for :space has been extended until :end_time.',
        ],
        'cancelled' => [
            'title' => 'Your booking was cancelled',
            'body' => 'The ADD team cancelled your booking for :space on :date, and :points points were returned to your wallet.',
        ],
        'cancelled_no_refund' => [
            'title' => 'Your booking was cancelled',
            'body' => 'The ADD team cancelled your booking for :space on :date.',
        ],
        'affected_by_maintenance' => [
            'title' => 'Booking affected by maintenance',
            'body' => ':space entered emergency maintenance, and our team will contact you about your booking.',
        ],
    ],

    'access' => [
        'passcode_ready' => [
            'title' => 'Your access code is ready',
            'body' => 'Your door code for :space is ready from :from to :to. Open the app to see it.',
        ],
        'revoked' => [
            'title' => 'Access code stopped',
            'body' => 'Your door code for :space was stopped for emergency maintenance.',
        ],
        'office_opened' => [
            'title' => 'Your office is ready',
            'body' => 'The door of :space is open to you from today.',
        ],
        'grace_expiring' => [
            'title' => 'Access Window Ending Soon',
            'body' => 'Your scheduled access for :space ends in :minutes minutes.',
        ],
    ],

    'reception' => [
        'arrival_queued' => [
            'title' => 'Member Arrival at Kiosk',
            'body' => ':member_name has checked in at the reception kiosk.',
        ],
        'arrival_confirmed' => [
            'title' => 'Welcome to ADD',
            'body' => 'Your arrival at :branch has been confirmed. Have a productive day!',
        ],
        'forgotten_session_alert' => [
            'title' => 'Forgotten Session Alert',
            'body' => 'Unclosed session for :member_name was automatically billed at midnight.',
        ],
        'session_settled' => [
            'title' => 'Session Checkout Complete',
            'body' => 'Your session payment of :amount :currency has been settled.',
        ],
    ],

    'wallet' => [
        'topped_up' => [
            'title' => 'Wallet Topped Up',
            'body' => 'Your wallet has been credited with :amount :currency (:points points).',
        ],
        'company_allocated' => [
            'title' => 'Credit from your company',
            'body' => ':company gave you :points points to use for :category.',
        ],
        'categories' => [
            'general' => 'general use',
            'cafe' => 'the café',
            'printing_internet' => 'printing and internet',
            'space_specific' => 'a specific space',
        ],
        'points_expiring' => [
            'title' => 'Points Expiring Soon',
            'body' => ':points points in your wallet will expire on :date.',
        ],
    ],

    'membership' => [
        'desk_needed' => [
            'title' => 'Dedicated desk to assign',
            'body' => 'A member subscribed to :plan in the app and needs a desk assigned.',
        ],
        'assigned' => [
            'title' => 'Your plan is active',
            'body' => 'Your :plan plan is active from :start to :end.',
        ],
        'started' => [
            'title' => 'Your plan has started',
            'body' => 'Your :plan plan started today, and its balances were added to your wallet.',
        ],
        'renewed' => [
            'title' => 'Plan renewed',
            'body' => 'Your :plan plan was renewed until :date.',
        ],
        'desk_assigned' => [
            'title' => 'Your dedicated desk is ready',
            'body' => 'Desk :desk in :space is yours.',
        ],
        'expiring_soon' => [
            'title' => 'Plan Expiring Soon',
            'body' => 'Your :plan_name subscription will expire in :days days.',
        ],
        'expired' => [
            'title' => 'Plan Expired',
            'body' => 'Your subscription to :plan_name has expired.',
        ],
    ],

    'company' => [
        'member_joined' => [
            'title' => 'Added to :company',
            'body' => 'You are now part of the :company team at ADD.',
        ],
        'member_removed' => [
            'title' => 'Removed from :company',
            'body' => 'You are no longer part of the :company team.',
        ],
        'door_access_granted' => [
            'title' => 'Door access updated',
            'body' => 'You can now open the :company office doors.',
        ],
        'door_access_revoked' => [
            'title' => 'Door access updated',
            'body' => 'You can no longer open the :company office doors.',
        ],
        'admin_assigned' => [
            'title' => 'You now manage :company',
            'body' => 'You can now manage :company, its members and its wallet.',
        ],
        'admin_removed' => [
            'title' => 'Admin rights removed',
            'body' => 'You no longer manage :company.',
        ],
    ],

    'account' => [
        'deactivated' => [
            'title' => 'Account Deactivated',
            'body' => 'Your account has been deactivated.',
        ],
        'blocked' => [
            'title' => 'Account Suspended',
            'body' => 'Your account has been suspended. Please contact ADD operations.',
        ],
        'activated' => [
            'title' => 'Account Activated',
            'body' => 'Your account has been reactivated. Welcome back!',
        ],
    ],

    'inquiry' => [
        'new_received' => [
            'title' => 'New Website Inquiry',
            'body' => 'Inquiry received from :sender_name regarding :subject.',
        ],
    ],

    'announcement' => [
        'new' => [
            'title' => 'New Announcement',
            'body' => ':title',
        ],
    ],

    'service_request' => [
        'status_updated' => [
            'title' => 'Update on your request',
            'body' => 'Your :service request is now :status.',
        ],
        'statuses' => [
            'pending' => 'pending',
            'in_progress' => 'in progress',
            'resolved' => 'resolved',
            'rejected' => 'rejected',
            'cancelled' => 'cancelled',
        ],
    ],

    'coworking' => [
        'session_started' => [
            'title' => 'Session Started',
            'body' => 'Reception confirmed you are here. Your session at :space has started.',
        ],
        'start_rejected' => [
            'title' => 'Session Not Started',
            'body' => 'Reception did not confirm your session. Please see the reception desk.',
        ],
        'session_ended' => [
            'title' => 'Session Ended',
            'body' => 'Your session at :space has ended. Tap to see the report.',
        ],
        'session_ended_unpaid' => [
            'title' => 'Session Ended',
            'body' => 'Your session at :space has ended. Tap to choose how to pay.',
        ],
        'arrival_expired' => [
            'title' => 'Your arrival was not confirmed',
            'body' => 'Reception did not confirm your arrival in time. Scan the code again or visit the desk.',
        ],
        'closed_by_system' => [
            'title' => 'Your session was closed automatically',
            'body' => 'Your session at :space was closed at :time because no check-out was recorded. Due: :points points.',
        ],
        'closed_by_system_no_charge' => [
            'title' => 'Your session was closed automatically',
            'body' => 'Your session at :space was closed at :time because no check-out was recorded.',
        ],
    ],

    'invoice' => [
        'issued' => [
            'title' => 'New invoice',
            'body' => 'Invoice :number for :amount :currency was issued, due on :due.',
        ],
    ],

    'lease' => [
        'contracted' => [
            'title' => 'Office lease signed',
            'body' => 'The lease of :space starts on :start and ends on :end.',
        ],
        'ended' => [
            'title' => 'Office lease ended',
            'body' => 'Access to :space stops from :date.',
        ],
    ],

    'payment' => [
        'received' => [
            'title' => 'Payment Received',
            'body' => 'Your payment for :purpose was received (:points points).',
        ],
        'expired' => [
            'title' => 'Reserved Package Released',
            'body' => 'The package you chose to pay for at reception was not paid in time and has been released.',
        ],
        'failed' => [
            'title' => 'Payment not completed',
            'body' => 'Your payment for :purpose did not go through, and nothing was charged. You can try again.',
        ],
        'reversed' => [
            'title' => 'Payment reversed',
            'body' => 'Your payment for :purpose of :points points was reversed. Contact us with any question.',
        ],
        'purposes' => [
            'wallet_top_up' => 'a wallet top-up',
            'plan_purchase' => 'a package',
            'booking' => 'a booking',
            'event_registration' => 'an event registration',
            'coworking_session' => 'a coworking session',
            'booking_extension' => 'a booking extension',
            'item_sale' => 'a purchase at reception',
        ],
    ],

    'event' => [
        'payment_due' => [
            'title' => 'Payment Due',
            'body' => 'Your seat at :event is confirmed. Pay :points points before the event to be let in.',
        ],
        'registration_approved' => [
            'title' => 'Registration accepted',
            'body' => 'Your seat at :event on :date is confirmed.',
        ],
        'registration_rejected' => [
            'title' => 'Registration not accepted',
            'body' => 'Sorry, your registration for :event was not accepted.',
        ],
        'registration_expired' => [
            'title' => 'Registration expired',
            'body' => 'Your registration for :event was not paid in time, and the seat was released.',
        ],
    ],

    'provider' => [
        'request_approved' => [
            'title' => 'Request approved',
            'body' => 'ADD approved your request: :subject.',
        ],
        'request_rejected' => [
            'title' => 'Request rejected',
            'body' => 'ADD rejected your request: :subject. Reason: :reason',
        ],
        // What a request was for, as :subject: its type, and its company when it has one.
        'request_subject_with_company' => ':type for :company',
        'request_types' => [
            'company_member' => 'adding an employee',
            'company_admin' => 'a company admin',
            'plan_design' => 'a plan design',
            'plan_assignment' => 'a plan assignment',
            'wallet_top_up' => 'a wallet top-up',
        ],
    ],

    // HR (packages/add/hr), sent through App\Domain\Hr\Adapters\AddHrNotifier.
    'hr' => [
        'alerts_digest' => [
            'title' => 'HR alerts',
            'body' => ':count HR alerts need attention (contracts, probations, documents).',
        ],
        'approval_pending' => [
            'title' => 'Approval needed',
            'body' => 'A request is waiting for your approval.',
        ],
        'approval_escalated' => [
            'title' => 'Overdue request',
            'body' => 'A request passed its approval deadline and was escalated to you.',
        ],
        'request_approved' => [
            'title' => 'Request approved',
            'body' => 'Your request has been approved.',
        ],
        'request_rejected' => [
            'title' => 'Request rejected',
            'body' => 'Your request has been rejected.',
        ],
        'payslip_issued' => [
            'title' => 'Your payslip is ready',
            'body' => 'Your payslip for :month has been issued.',
        ],
        'benefit_granted' => [
            'title' => 'You received a benefit',
            'body' => ':amount points from :benefit_en were added to your wallet.',
        ],
        'benefit_grants_failed' => [
            'title' => 'Benefits not delivered',
            'body' => ':count benefit grants for :month could not be delivered. Review them and retry.',
        ],
        'interview_scheduled' => [
            'title' => 'You have an interview to conduct',
            'body' => 'Interview with :candidate for :opening_en on :at.',
        ],
        'review_self_open' => [
            'title' => 'Your self review is open',
            'body' => 'Rate yourself on your goals and competencies for :cycle_en.',
        ],
        'review_manager_open' => [
            'title' => 'Reviews are waiting for you',
            'body' => 'Rate the employees you review in :cycle_en.',
        ],
        'review_finalized' => [
            'title' => 'Your review is ready',
            'body' => 'The result of :cycle_en is available.',
        ],
        'training_enrolled' => [
            'title' => 'You are enrolled in a training',
            'body' => ':course_en starts on :starts_on.',
        ],
        'training_cancelled' => [
            'title' => 'Training cancelled',
            'body' => 'The :course_en session on :starts_on has been cancelled.',
        ],
    ],

    // Inventory (packages/add/inventory), sent through
    // App\Domain\Inventory\Adapters\AddInventoryNotifier.
    'inventory' => [
        'maintenance_due' => [
            'title' => 'Maintenance due',
            'body' => ':title for asset :asset is due on :date.',
        ],
        'warranty_ending' => [
            'title' => 'Warranty ending',
            'body' => 'The warranty of asset :asset ends on :date.',
        ],
    ],

];
