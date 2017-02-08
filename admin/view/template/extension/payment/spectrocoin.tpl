<?php echo $header; ?><?php echo $column_left; ?>

<?php if (isset($error_warning)): ?>
<div class="warning"><?php echo $error_warning; ?></div>
<?php endif; ?>

<div id="content">
    <div class="page-header">
        <div class="container-fluid">
            <div class="pull-right">
                <button type="submit" form="form-spectrocoin" data-toggle="tooltip" title="<?php echo $button_save; ?>" class="btn btn-primary"><i class="fa fa-save"></i></button>
                <a href="<?php echo $cancel; ?>" data-toggle="tooltip" title="<?php echo $button_cancel; ?>" class="btn btn-default"><i class="fa fa-reply"></i></a></div>
            <h1><?php echo $heading_title; ?></h1>
            <ul class="breadcrumb">
                <?php foreach ($breadcrumbs as $breadcrumb) { ?>
                <li><a href="<?php echo $breadcrumb['href']; ?>"><?php echo $breadcrumb['text']; ?></a></li>
                <?php } ?>
            </ul>
        </div>
    </div>

    <div class="container-fluid">
        <?php if (isset($error_warning)) { ?>
        <div class="alert alert-danger"><i class="fa fa-exclamation-circle"></i> <?php echo $error_warning; ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php } ?>
        <div class="panel panel-default">
            <div class="panel-heading">
                <h3 class="panel-title"><i class="fa fa-pencil"></i> <?php echo $text_edit; ?></h3>
            </div>


            <div class="panel-body">
                <form action="<?php echo $action; ?>" method="post" enctype="multipart/form-data" id="form-spectrocoin" class="form-horizontal">
                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="entry_title"><?php echo $entry_title; ?></label>
                        <div class="col-sm-10">
                            <input type="text" name="spectrocoin_title" value="<?php echo $spectrocoin_title ? $spectrocoin_title : $text_default_title; ?>" class="form-control" />
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="entry_project"><?php echo $entry_project; ?></label>
                        <div class="col-sm-10">
                            <input type="text" name="spectrocoin_project" value="<?php echo $spectrocoin_project; ?>" class="form-control" />
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="entry_merchant"><?php echo $entry_merchant; ?></label>
                        <div class="col-sm-10">
                            <input type="text" name="spectrocoin_merchant" value="<?php echo $spectrocoin_merchant; ?>" class="form-control" />
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="entry_private_key">
                            <span data-toggle="tooltip" title="" data-original-title="<?php echo $entry_private_key_tooltip ?>"><?php echo $entry_private_key; ?></span>
                        </label>
                        <div class="col-sm-10">
                            <textarea name="spectrocoin_private_key" class="form-control"><?php echo '' ?></textarea>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-sm-2 control-label" for=""><?php echo $entry_display_payments; ?></label>
                        <div class="col-sm-10">
                            <select name="spectrocoin_status" class="form-control">
                                <?php if($spectrocoin_status == 1): ?>
                                <option value="1" selected="selected"><?php echo $text_yes; ?></option>
                                <option value="0"><?php echo $text_no; ?></option>
                                <?php else: ?>
                                <option value="1"><?php echo $text_yes; ?></option>
                                <option value="0" selected="selected"><?php echo $text_no; ?></option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>


                    <div class="form-group">
                        <label class="col-sm-2 control-label" for="input-sort-order"><?php echo $entry_sort_order; ?></label>
                        <div class="col-sm-10">
                            <input type="text" name="spectrocoin_sort_order" value="<?php echo $spectrocoin_sort_order; ?>" placeholder="<?php echo $entry_sort_order; ?>" id="input-sort-order" class="form-control" />
                        </div>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<?php echo $footer; ?>