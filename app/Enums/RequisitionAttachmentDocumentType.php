<?php

namespace App\Enums;

enum RequisitionAttachmentDocumentType: string
{
    case Quotation = 'quotation';
    case Grn = 'grn';
    case Receipt = 'receipt';
    case Invoice = 'invoice';
    case Other = 'other';
}
