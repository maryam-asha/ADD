<?php

return [

    'booking' => [
        'pending_approval' => [
            'title' => 'Booking Pending Approval',
            'body' => 'New booking request for :space by :member_name requires approval.',
        ],
        'approved' => [
            'title' => 'Booking Approved',
            'body' => 'Your booking for :space on :date has been approved.',
        ],
        'rejected' => [
            'title' => 'Booking Rejected',
            'body' => 'Your booking for :space has been rejected. Reason: :reason',
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
            'title' => 'Booking Cancelled',
            'body' => 'Your booking for :space has been cancelled and refunded to your wallet.',
        ],
        'affected_by_maintenance' => [
            'title' => 'Booking Affected by Maintenance',
            'body' => ':space has entered emergency maintenance. Operations will contact you regarding your booking.',
        ],
    ],

    'access' => [
        'passcode_ready' => [
            'title' => 'Access Code Ready',
            'body' => 'Your passcode for :space is :code (or unlock via the app).',
        ],
        'revoked_maintenance' => [
            'title' => 'Access Revoked',
            'body' => 'Access passcode for :space has been revoked due to maintenance.',
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
            'title' => 'Points Allocated',
            'body' => ':company allocated :points points to your wallet for :category.',
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
        'subscribed' => [
            'title' => 'Plan Activated',
            'body' => 'Your subscription to :plan_name is now active.',
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
            'title' => 'Added to Company',
            'body' => 'You have been added to the team of :company at ADD.',
        ],
        'door_access_changed' => [
            'title' => 'Door Access Updated',
            'body' => 'Your door access for :company office doors has been :status.',
        ],
        'admin_assigned' => [
            'title' => 'Admin Role Assigned',
            'body' => 'You have been granted administrator rights for :company.',
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

    'ticket' => [
        'created' => [
            'title' => 'Service Ticket Received',
            'body' => 'Ticket #:ticket_id has been created for your request.',
        ],
        'status_updated' => [
            'title' => 'Service Ticket Updated',
            'body' => 'Your ticket #:ticket_id status is now :status.',
        ],
    ],

    'coworking' => [
        'session_ended' => [
            'title' => 'Session Ended',
            'body' => 'Your session at :space has ended. Tap to see the report.',
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
        'purposes' => [
            'wallet_top_up' => 'a wallet top-up',
            'plan_purchase' => 'a package',
            'booking' => 'a booking',
            'event_registration' => 'an event registration',
            'coworking_session' => 'a coworking session',
            'booking_extension' => 'a booking extension',
        ],
    ],

    'event' => [
        'payment_due' => [
            'title' => 'Payment Due',
            'body' => 'Your seat at :event is confirmed. Pay :points points before the event to be let in.',
        ],
    ],

    'provider' => [
        'request_approved' => [
            'title' => 'Request approved',
            'body' => 'Your request has been approved.',
        ],
        'request_rejected' => [
            'title' => 'Request rejected',
            'body' => 'Your request has been rejected.',
        ],
    ],

];
