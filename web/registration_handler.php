<?php
namespace MRBS;

require "defaultincludes.inc";

use MRBS\Form\Form;


// Cancel a user's registration
function cancel($registration_id)
{
  $registration = get_registration_by_id($registration_id);

  if (!isset($registration))
  {
    return;
  }

  $entry = get_entry_by_id($registration['entry_id']);

  // Check that the user is authorised for this operation
  if (!isset($entry) ||
      (!getWritable($registration['fullname'], $entry['room_id']) &&
       !getWritable($registration['create_by'], $entry['room_id'])))
  {
    return;
  }

  // Check that it is not too late to cancel a registration
  if (!is_book_admin($entry['room_id']) && entry_registration_cancellation_has_closed($entry))
  {
    return;
  }

  // They are authorised, so go ahead and delete the registration
  $sql = "DELETE FROM " . _tbl('participants') . "
                WHERE id=:registration_id";

  $sql_params = array(
      ':registration_id' => $registration_id
    );

  db()->command($sql, $sql_params);
}


// Register a user for an event
function register($fullname, $teilnehmertyp, $email, $event_id)
{
  $entry = get_entry_by_id($event_id);

  // Check that the user is authorised for this operation
  if (!isset($entry) || !(can_register_others($entry['room_id']) || getWritable($fullname, $entry['room_id'])))
  {
    return;
  }

  // Check that the user is an an admin or else that the entry is open for registration
  if (!is_book_admin($entry['room_id']) && !entry_registration_is_open($entry))
  {
    return;
  }

  // Obtain a lock to make sure no one else registers after we've checked that there
  // are spare places
  db()->mutex_lock(_tbl('participants'));

  $data = get_booking_info($event_id, false);

  // Check that registration is allowed ...
  if (!empty($data['allow_registration']))
  {
    // ... and that there are spare places
    $n_registered = count($data['registrants']);
    if (empty($data['registrant_limit_enabled']) ||

      ($data['registrant_limit'] > $n_registered))
    {
      // ... and that the user hasn't already been registered
      if (!in_arrayi($fullname, array_column($data['registrants'], 'fullname')))
      {
        $mrbs_user = session()->getCurrentUser();
        $mrbs_username = (isset($mrbs_user)) ? $mrbs_user->username : null;
        // then register the user
        //hier
        $sql = "INSERT INTO " . _tbl('participants') . " (entry_id, fullname, create_by, registered, teilnehmertyp, email, ical_uid_part)
                     VALUES (:entry_id, :fullname, :create_by, :registered, :teilnehmertyp, :email, :ical_uid)";

        $sql_params = array(
          ':entry_id'   => $event_id,
          ':fullname'   => $fullname,
          ':create_by'  => $mrbs_username,
          ':registered' => time(),
          ':teilnehmertyp' => $teilnehmertyp, //Custom-AM
          ':email' => $email, //Custom-AM
          ':ical_uid' => $entry['ical_uid'] //Custom-AM
        );

        db()->command($sql, $sql_params);
      }
    }
  }

  // Release the lock
  db()->mutex_unlock(_tbl('participants'));
}

// Check the CSRF token.
Form::checkToken();

// Check the user is authorised for this page
checkAuthorised(this_page());

// Get the form vars
$action = get_form_var('action', 'string');
$event_id = get_form_var('event_id', 'int');
$returl = get_form_var('returl', 'string');

// Take the appropriate action.  The individual functions check that the user
// is authorised to take the action.
switch ($action)
{
  case 'cancel':
    $registration_id = get_form_var('registration_id', 'int');
    cancel($registration_id);
    break;
  case 'register':
    $fullname = get_form_var('fullname', 'string');
    $teilnehmertyp = get_form_var('teilnehmertyp', 'string');
    $email = get_form_var('e-mail', 'string');
    register($fullname, $teilnehmertyp, $email, $event_id);
    break;
  default:
    trigger_error("Unknown action '$action'", E_USER_WARNING);
    break;
}

location_header($returl);
