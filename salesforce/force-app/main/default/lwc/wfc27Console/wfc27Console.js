import { LightningElement, track } from 'lwc';
import objects from '@salesforce/apex/WFC27_Admin.objects';
import fields from '@salesforce/apex/WFC27_Admin.fields';
import status from '@salesforce/apex/WFC27_Admin.status';
import saveObject from '@salesforce/apex/WFC27_Admin.saveObject';
import saveField from '@salesforce/apex/WFC27_Admin.saveField';
import setBatchSize from '@salesforce/apex/WFC27_Admin.setBatchSize';
import startSchedules from '@salesforce/apex/WFC27_Admin.startSchedules';
import journey from '@salesforce/apex/WFC27_Admin.journey';
import startBaseSync from '@salesforce/apex/WFC27_Admin.startBaseSync';
import fieldRules from '@salesforce/apex/WFC27_Admin.fieldRules';
import { ShowToastEvent } from 'lightning/platformShowToastEvent';

export default class Wfc27Console extends LightningElement {
  @track rows = [];
  @track fieldRows = [];
  @track fieldRules = [];
  @track status;
  error;
  selectedObject;
  bindingId;
  postType = '';
  eligibleStatus = 'draft';
  active = false;
  draftDays;
  binDays;
  batchSize;
  sourceField;
  targetType = 'post';
  wpKey = '';
  searchId = '';
  @track journeyData;
  packetColumns = [
    { label: 'Packet', fieldName: 'Id' },
    { label: 'Status', fieldName: 'Status__c' },
    { label: 'Posts', fieldName: 'Post_Count__c', type: 'number' },
    { label: 'Meta', fieldName: 'Meta_Count__c', type: 'number' },
    { label: 'Error', fieldName: 'Error__c' }
  ];
  objectColumns = [
    { label: 'Salesforce object', fieldName: 'displayName' },
    { label: 'WordPress post type', fieldName: 'postType' },
    { label: 'Eligible status', fieldName: 'eligibleStatus' },
    { label: 'Eligibility field', fieldName: 'eligibilityLabel' },
    { label: 'Active', fieldName: 'active', type: 'boolean' },
    { label: 'Sync', fieldName: 'syncLabel' },
    { type: 'button', typeAttributes: { label: 'Edit', name: 'edit', variant: 'base' } }
  ];
  journeyColumns = [{ label: 'Queue ID', fieldName: 'Id' }, { label: 'Kind', fieldName: 'Kind__c' },
    { label: 'Status', fieldName: 'Status__c' }, { label: 'Packet', fieldName: 'Packet__c' }];
  fieldColumns = [{ label: 'Salesforce field', fieldName: 'Source_Field__c' }, { label: 'Target', fieldName: 'Target_Type__c' },
    { label: 'WordPress key', fieldName: 'WP_Key__c' }, { label: 'Active', fieldName: 'Active__c', type: 'boolean' }];
  targetOptions = [{ label: 'Post field', value: 'post' }, { label: 'Post meta', value: 'meta' }];
  statusOptions = [{ label: 'Draft', value: 'draft' }, { label: 'Publish', value: 'publish' }, { label: 'Pending review', value: 'pending' }, { label: 'Private', value: 'private' }];

  connectedCallback() { this.refresh(); }
  async refresh() {
    try { [this.rows, this.status] = await Promise.all([objects(), status()]); this.error = null; }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  get objectOptions() { return this.rows.filter(row => !row.bindingId || row.api === this.selectedObject).map(row => ({ label: `${row.label} (${row.api})${row.hasFlag ? '' : ' — no eligibility field'}`, value: row.api })); }
  get configuredBindings() { return this.rows.filter(row => row.bindingId).map(row => ({ ...row,
    displayName: `${row.label} (${row.api})`,
    eligibilityLabel: row.hasFlag ? 'Present' : 'Missing',
    syncLabel: !row.active ? 'Paused' : row.hasFlag ? 'Ready' : 'Waiting for eligibility field'
  })).sort((a, b) => a.displayName.localeCompare(b.displayName)); }
  get objectFormTitle() { return this.bindingId ? 'Edit object binding' : 'Add object binding'; }
  get selectedRow() { return this.rows.find(row => row.api === this.selectedObject); }
  get fieldOptions() { return this.fieldRows.map(row => ({ label: `${row.label} (${row.api})`, value: row.api })); }
  get saveDisabled() { return !this.selectedRow || !this.postType; }
  get baseDisabled() { return !this.bindingId || !this.active || !this.selectedRow?.hasFlag; }
  get fieldSaveDisabled() { return !this.bindingId || !this.sourceField || !this.wpKey; }
  async chooseObject(event) {
    this.selectedObject = event.detail.value;
    const row = this.selectedRow;
    this.bindingId = row.bindingId;
    this.postType = row.postType || '';
    this.eligibleStatus = row.eligibleStatus || 'draft';
    this.active = row.active;
    this.draftDays = row.draftDays;
    this.binDays = row.binDays;
    this.sourceField = null;
    try { this.fieldRows = await fields({ objectApi: this.selectedObject }); this.fieldRules = this.bindingId ? await fieldRules({ bindingId: this.bindingId }) : []; this.error = null; }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  async editObjectRule(event) {
    if (event.detail.action.name === 'edit') await this.chooseObject({ detail: { value: event.detail.row.api } });
  }
  changePostType(event) { this.postType = event.detail.value; }
  changeEligibleStatus(event) { this.eligibleStatus = event.detail.value; }
  changeActive(event) { this.active = event.detail.checked; }
  changeDraft(event) { this.draftDays = event.detail.value; }
  changeBin(event) { this.binDays = event.detail.value; }
  changeBatch(event) { this.batchSize = event.detail.value; }
  changeSearch(event) { this.searchId = event.detail.value; }
  chooseField(event) { this.sourceField = event.detail.value; }
  chooseTarget(event) { this.targetType = event.detail.value; }
  changeKey(event) { this.wpKey = event.detail.value; }
  async saveObjectRule() {
    try {
      this.bindingId = await saveObject({ objectApi: this.selectedObject, postType: this.postType, eligibleStatus: this.eligibleStatus, active: this.active,
        draftDays: this.draftDays === '' || this.draftDays == null ? null : Number(this.draftDays),
        binDays: this.binDays === '' || this.binDays == null ? null : Number(this.binDays) });
      this.dispatchEvent(new ShowToastEvent({ title: 'Object binding saved', variant: 'success' }));
      await this.refresh();
    } catch (error) { this.error = error.body?.message || error.message; }
  }
  async saveFieldRule() {
    try {
      await saveField({ bindingId: this.bindingId, sourceField: this.sourceField, targetType: this.targetType, wpKey: this.wpKey });
      this.dispatchEvent(new ShowToastEvent({ title: 'Field binding added', variant: 'success' }));
      this.wpKey = '';
      this.fieldRules = await fieldRules({ bindingId: this.bindingId });
    } catch (error) { this.error = error.body?.message || error.message; }
  }
  async saveBatch() {
    try {
      await setBatchSize({ size: Number(this.batchSize) });
      this.dispatchEvent(new ShowToastEvent({ title: 'Batch size saved', variant: 'success' }));
      await this.refresh();
    } catch (error) { this.error = error.body?.message || error.message; }
  }
  async startJobs() {
    try { await startSchedules(); this.dispatchEvent(new ShowToastEvent({ title: 'Schedules started', variant: 'success' })); await this.refresh(); }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  async queueBaseSync() {
    try { await startBaseSync({ bindingId: this.bindingId }); this.dispatchEvent(new ShowToastEvent({ title: 'Base sync queued', variant: 'success' })); }
    catch (error) { this.error = error.body?.message || error.message; }
  }
  async showJourney() {
    try { this.journeyData = await journey({ sourceId: this.searchId }); this.error = null; }
    catch (error) { this.error = error.body?.message || error.message; }
  }
}
