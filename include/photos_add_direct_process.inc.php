<?php
// +-----------------------------------------------------------------------+
// | Piwigo - a PHP based photo gallery                                    |
// +-----------------------------------------------------------------------+
// | Copyright(C) 2008-2014 Piwigo Team                  http://piwigo.org |
// | Copyright(C) 2003-2008 PhpWebGallery Team    http://phpwebgallery.net |
// | Copyright(C) 2002-2003 Pierrick LE GALL   http://le-gall.net/pierrick |
// +-----------------------------------------------------------------------+
// | This program is free software; you can redistribute it and/or modify  |
// | it under the terms of the GNU General Public License as published by  |
// | the Free Software Foundation                                          |
// |                                                                       |
// | This program is distributed in the hope that it will be useful, but   |
// | WITHOUT ANY WARRANTY; without even the implied warranty of            |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU      |
// | General Public License for more details.                              |
// |                                                                       |
// | You should have received a copy of the GNU General Public License     |
// | along with this program; if not, write to the Free Software           |
// | Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307, |
// | USA.                                                                  |
// +-----------------------------------------------------------------------+


if (isset($_GET['processed']))
{
//   echo '<pre>POST'."\n"; print_r($_POST); echo '</pre>';
//   echo '<pre>FILES'."\n"; print_r($_FILES); echo '</pre>';
//   echo '<pre>SESSION'."\n"; print_r($_SESSION); echo '</pre>';
//   exit();

  // sometimes, you have submitted the form but you have nothing in $_POST
  // and $_FILES. This may happen when you have an HTML upload and you
  // exceeded the post_max_size (but not the upload_max_size)
  if (!isset($_POST['submit_upload']))
  {
    $page['errors'][] = l10n(
      'The uploaded files exceed the post_max_size directive in php.ini: %sB',
      ini_get('post_max_size')
      );
  }
  else
  {
    $category_id = $_POST['category'];
  }

  if (isset($_POST['onUploadError']) and is_array($_POST['onUploadError']) and count($_POST['onUploadError']) > 0)
  {
    foreach ($_POST['onUploadError'] as $error)
    {
      $page['errors'][] = $error;
    }
  }
    
  $image_ids = array();

  $archive_batch_rejected = false;
  if (isset($_FILES['image_upload']['error']) and is_array($_FILES['image_upload']['error']))
  {
    foreach ($_FILES['image_upload']['error'] as $idx => $error)
    {
      if (UPLOAD_ERR_OK == $error)
      {
        if (!isset($_FILES['image_upload']['name'][$idx]) or !is_string($_FILES['image_upload']['name'][$idx]))
        {
          $archive_batch_rejected = true;
          break;
        }

        $extension = pathinfo($_FILES['image_upload']['name'][$idx], PATHINFO_EXTENSION);
        if ('zip' == strtolower($extension))
        {
          $archive_batch_rejected = true;
          break;
        }
      }
    }
  }

  if ($archive_batch_rejected)
  {
    $page['errors'][] = l10n('ZIP archives are not supported');
  }

  if (!$archive_batch_rejected and isset($_FILES) and !empty($_FILES['image_upload']))
  {
    $starttime = get_moment();

  foreach ($_FILES['image_upload']['error'] as $idx => $error)
  {
    if (UPLOAD_ERR_OK == $error)
    {
      $images_to_add = array();
      
      $extension = pathinfo($_FILES['image_upload']['name'][$idx], PATHINFO_EXTENSION);
      if (is_valid_image_extension($extension))
      {
        $images_to_add[] = array(
          'source_filepath' => $_FILES['image_upload']['tmp_name'][$idx],
          'original_filename' => $_FILES['image_upload']['name'][$idx],
          );
      }

      foreach ($images_to_add as $image_to_add)
      {
        $image_id = add_uploaded_file(
          $image_to_add['source_filepath'],
          $image_to_add['original_filename'],
          array($category_id),
          $_POST['level']
          );

        $image_ids[] = $image_id;

        // TODO: if $image_id is not an integer, something went wrong
      }
    }
    else
    {
      $error_message = file_upload_error_message($error);
      
      $page['errors'][] = l10n(
        'Error on file "%s" : %s',
        $_FILES['image_upload']['name'][$idx],
        $error_message
        );
    }
  }
  
  $endtime = get_moment();
  $elapsed = ($endtime - $starttime) * 1000;
  // printf('%.2f ms', $elapsed);

  } // if (!empty($_FILES))

  if (!$archive_batch_rejected and isset($_POST['upload_id']))
  {
    // we're on a multiple upload, with uploadify and so on
    if (isset($_SESSION['uploads_error'][ $_POST['upload_id'] ]))
    {
      foreach ($_SESSION['uploads_error'][ $_POST['upload_id'] ] as $error)
      {
        $page['errors'][] = $error;
      }
    }

    if (isset($_SESSION['uploads'][ $_POST['upload_id'] ]))
    {
      $image_ids = $_SESSION['uploads'][ $_POST['upload_id'] ];
    }
  }
  
  $page['thumbnails'] = array();
  foreach ($image_ids as $image_id)
  {
    // we could return the list of properties from the add_uploaded_file
    // function, but I like the "double check". And it costs nothing
    // compared to the upload process.
    $thumbnail = array();
      
    $query = '
SELECT
    id,
    file,
    path
  FROM '.IMAGES_TABLE.'
  WHERE id = '.$image_id.'
;';
    $image_infos = pwg_db_fetch_assoc(pwg_query($query));

    $thumbnail['file'] = $image_infos['file'];
    
    $thumbnail['src'] = DerivativeImage::thumb_url($image_infos);

    // TODO: when implementing this plugin in Piwigo core, we should have
    // a function get_image_name($name, $file) (if name is null, then
    // compute a temporary name from filename) that would be also used in
    // picture.php. UPDATE: in fact, "get_name_from_file($file)" already
    // exists and is used twice (batch_manager_unit + comments, but not in
    // picture.php I don't know why) with the same pattern if
    // (empty($name)) {$name = get_name_from_file($file)}, a clean
    // function get_image_name($name, $file) would be better
    $thumbnail['title'] = get_name_from_file($image_infos['file']);

    $thumbnail['link'] = get_root_url().'admin.php?page=photo-'.$image_id.'&amp;cat_id='.$category_id;

    $page['thumbnails'][] = $thumbnail;
  }
  
  if (!empty($page['thumbnails']))
  {
    $page['infos'][] = l10n('%d photos uploaded', count($page['thumbnails']));
    
    if (0 != $_POST['level'])
    {
      $page['infos'][] = l10n(
        'Privacy level set to "%s"',
        l10n(sprintf('Level %d', $_POST['level']))
        );
    }

    $query = '
SELECT
    COUNT(*)
  FROM '.IMAGE_CATEGORY_TABLE.'
  WHERE category_id = '.$category_id.'
;';
    list($count) = pwg_db_fetch_row(pwg_query($query));
    $category_name = get_cat_display_name_from_id($category_id, 'admin.php?page=album-');
    
    // information
    $page['infos'][] = l10n(
      'Album "%s" now contains %d photos',
      '<em>'.$category_name.'</em>',
      $count
      );
    
    $page['batch_link'] = PHOTOS_ADD_BASE_URL.'&batch='.implode(',', $image_ids);
  }
}

?>